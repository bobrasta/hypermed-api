<?php

namespace Hypermed\Messaging\Services;

use Hypermed\Messaging\Events\ConversationAdded;
use Hypermed\Messaging\Events\ConversationRemoved;
use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\MessagingConfig;
use Hypermed\Messaging\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(private readonly MessagingService $messaging) {}

    /** Active participation row or a 403 (participant missing) / 404 (conversation missing). */
    public function participantOrFail(int $conversationId, int $userId): Participant
    {
        $participant = Participant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->with('conversation')
            ->first();

        if ($participant && $participant->conversation) {
            return $participant;
        }

        throw Conversation::query()->whereKey($conversationId)->exists()
            ? MessagingException::notParticipant()
            : MessagingException::notFound('Conversation not found.');
    }

    /**
     * Find or create the unique direct conversation between two users.
     *
     * @return array{0: Conversation, 1: bool} [conversation, created]
     */
    public function findOrCreateDirect(int $userId, int $otherUserId): array
    {
        if ($userId === $otherUserId) {
            throw MessagingException::validation(['user_id' => ['You cannot start a conversation with yourself.']]);
        }

        $key = Conversation::directKey($userId, $otherUserId);

        if ($existing = Conversation::query()->where('direct_key', $key)->first()) {
            $this->reactivate($existing, [$userId, $otherUserId]);

            return [$existing, false];
        }

        try {
            $conversation = DB::transaction(function () use ($key, $userId, $otherUserId) {
                $now = now();
                $conversation = Conversation::query()->create([
                    'type' => Conversation::TYPE_DIRECT,
                    'direct_key' => $key,
                    'created_by' => $userId,
                    'last_activity_at' => $now,
                ]);
                foreach ([$userId, $otherUserId] as $uid) {
                    Participant::query()->create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $uid,
                        'role' => Participant::ROLE_MEMBER,
                        'joined_at' => $now,
                    ]);
                }

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // Both users opened the chat at the same moment.
            return [Conversation::query()->where('direct_key', $key)->firstOrFail(), false];
        }

        Realtime::dispatch(new ConversationAdded([$userId, $otherUserId], (int) $conversation->id));

        return [$conversation, true];
    }

    /** @param array<int,int> $userIds */
    public function createGroup(int $creatorId, string $title, array $userIds): Conversation
    {
        $members = array_values(array_unique(array_filter(array_map('intval', $userIds), fn ($id) => $id !== $creatorId)));

        $conversation = DB::transaction(function () use ($creatorId, $title, $members) {
            $now = now();
            $conversation = Conversation::query()->create([
                'type' => Conversation::TYPE_GROUP,
                'title' => $title,
                'created_by' => $creatorId,
                'last_activity_at' => $now,
            ]);
            $rows = [['conversation_id' => $conversation->id, 'user_id' => $creatorId, 'role' => Participant::ROLE_ADMIN, 'joined_at' => $now]];
            foreach ($members as $uid) {
                $rows[] = ['conversation_id' => $conversation->id, 'user_id' => $uid, 'role' => Participant::ROLE_MEMBER, 'joined_at' => $now];
            }
            Participant::query()->insert($rows);

            return $conversation;
        });

        $system = $this->messaging->sendSystemMessage((int) $conversation->id, $this->userName($creatorId).' created the group "'.$title.'"', [
            'event' => 'group.created', 'actor_id' => $creatorId,
        ]);
        $this->messaging->advanceReadPointer((int) $conversation->id, $creatorId, (int) $system->id);
        Realtime::dispatch(new ConversationAdded(array_merge([$creatorId], $members), (int) $conversation->id));

        return $conversation->refresh();
    }

    /**
     * Add users to a group (admins only). Re-activates users who had left.
     *
     * @param  array<int,int>  $userIds
     * @return array<int,int> ids actually added
     */
    public function addParticipants(Participant $actor, array $userIds): array
    {
        $conversation = $actor->conversation;
        $this->assertGroupAdmin($actor);

        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $max = (int) config('messaging.groups.max_participants', 200);

        $added = DB::transaction(function () use ($conversation, $userIds, $max) {
            $existing = Participant::query()->where('conversation_id', $conversation->id)
                ->whereIn('user_id', $userIds)->lockForUpdate()->get()->keyBy('user_id');
            $active = Participant::query()->where('conversation_id', $conversation->id)->whereNull('left_at')->count();
            $now = now();
            $added = [];
            // New members start "caught up": history is visible but not unread.
            $lastId = $conversation->fresh()->last_message_id;

            foreach ($userIds as $uid) {
                $row = $existing->get($uid);
                if ($row && $row->left_at === null) {
                    continue;
                }
                if ($active + count($added) >= $max) {
                    throw MessagingException::validation(['user_ids' => ["A group can have at most {$max} participants."]]);
                }
                if ($row) {
                    $row->forceFill(['left_at' => null, 'joined_at' => $now, 'role' => Participant::ROLE_MEMBER,
                        'last_read_message_id' => $lastId, 'last_delivered_message_id' => $lastId])->save();
                } else {
                    Participant::query()->create([
                        'conversation_id' => $conversation->id, 'user_id' => $uid, 'role' => Participant::ROLE_MEMBER,
                        'joined_at' => $now, 'last_read_message_id' => $lastId, 'last_delivered_message_id' => $lastId,
                    ]);
                }
                $added[] = $uid;
            }

            return $added;
        });

        if ($added !== []) {
            $names = implode(', ', array_map(fn ($id) => $this->userName($id), $added));
            $system = $this->messaging->sendSystemMessage((int) $conversation->id, $this->userName($actor->user_id).' added '.$names, [
                'event' => 'participants.added', 'actor_id' => (int) $actor->user_id, 'user_ids' => $added,
            ]);
            $this->messaging->advanceReadPointer((int) $conversation->id, (int) $actor->user_id, (int) $system->id);
            Realtime::dispatch(new ConversationAdded($added, (int) $conversation->id));
        }

        return $added;
    }

    /** Leave (self) or remove someone (group admin). */
    public function removeParticipant(Participant $actor, int $targetUserId): void
    {
        $conversation = $actor->conversation;
        $self = $targetUserId === (int) $actor->user_id;

        if (! $self) {
            $this->assertGroupAdmin($actor);
        }

        $target = $self ? $actor : Participant::query()
            ->where('conversation_id', $conversation->id)->where('user_id', $targetUserId)->whereNull('left_at')->first();
        if (! $target) {
            throw MessagingException::notFound('That user is not a participant.');
        }

        DB::transaction(function () use ($conversation, $target) {
            $target->forceFill(['left_at' => now()])->save();

            // Keep at least one admin in a group.
            if ($conversation->isGroup() && $target->isAdmin()) {
                $hasAdmin = Participant::query()->where('conversation_id', $conversation->id)
                    ->whereNull('left_at')->where('role', Participant::ROLE_ADMIN)->exists();
                if (! $hasAdmin) {
                    Participant::query()->where('conversation_id', $conversation->id)->whereNull('left_at')
                        ->orderBy('joined_at')->orderBy('id')->first()
                        ?->forceFill(['role' => Participant::ROLE_ADMIN])->save();
                }
            }
        });

        if ($conversation->isGroup()) {
            $text = $self
                ? $this->userName($targetUserId).' left the group'
                : $this->userName($actor->user_id).' removed '.$this->userName($targetUserId);
            $system = $this->messaging->sendSystemMessage((int) $conversation->id, $text, [
                'event' => $self ? 'participant.left' : 'participant.removed',
                'actor_id' => (int) $actor->user_id, 'user_id' => $targetUserId,
            ]);
            if (! $self) {
                $this->messaging->advanceReadPointer((int) $conversation->id, (int) $actor->user_id, (int) $system->id);
            }
        }

        Realtime::dispatch(new ConversationRemoved([$targetUserId], (int) $conversation->id));
    }

    public function mute(Participant $participant, ?\DateTimeInterface $until): void
    {
        $participant->forceFill(['muted_until' => $until])->save();
    }

    private function assertGroupAdmin(Participant $actor): void
    {
        if (! $actor->conversation->isGroup()) {
            throw MessagingException::forbidden('Participants can only be managed in group conversations.');
        }
        if (! $actor->isAdmin()) {
            throw MessagingException::forbidden('Only group admins can manage participants.');
        }
    }

    /** @param array<int,int> $userIds */
    private function reactivate(Conversation $conversation, array $userIds): void
    {
        $reactivated = Participant::query()->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $userIds)->whereNotNull('left_at')->pluck('user_id')->all();
        if ($reactivated === []) {
            return;
        }
        Participant::query()->where('conversation_id', $conversation->id)
            ->whereIn('user_id', $reactivated)->update(['left_at' => null, 'joined_at' => now()]);
        Realtime::dispatch(new ConversationAdded(array_map('intval', $reactivated), (int) $conversation->id));
    }

    private function userName(int $userId): string
    {
        $user = MessagingConfig::userInstance()->newQuery()->find($userId);

        return $user ? (string) $user->getAttribute(MessagingConfig::nameColumn()) : 'Unknown user';
    }
}
