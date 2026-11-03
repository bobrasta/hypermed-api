<?php

namespace Hypermed\Messaging\Services;

use Closure;
use Hypermed\Messaging\Contracts\EntityLinkResolver;
use Hypermed\Messaging\Events\ConversationRead;
use Hypermed\Messaging\Events\DeliveryReceiptUpdated;
use Hypermed\Messaging\Events\MessageDeleted;
use Hypermed\Messaging\Events\MessageEdited;
use Hypermed\Messaging\Events\MessageSent;
use Hypermed\Messaging\Events\ReadReceiptUpdated;
use Hypermed\Messaging\Events\UserMessageCreated;
use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Http\Resources\MessageResource;
use Hypermed\Messaging\Jobs\SendPushJob;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\MessagePreview;
use Hypermed\Messaging\Support\Realtime;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public service other modules (approvals, tickets...) call to post into
 * conversations, plus the message lifecycle used by the HTTP controllers.
 */
class MessagingService
{
    public function __construct(private readonly EntityLinkResolver $entities) {}

    /**
     * Post a system message (sender_id NULL). Broadcast and pushed like a normal message.
     */
    public function sendSystemMessage(int $conversationId, string $body, array $meta = []): Message
    {
        $conversation = $this->conversationOrFail($conversationId);

        return $this->createMessage($conversation, null, [
            'type' => Message::TYPE_SYSTEM,
            'body' => $body,
            'meta' => $meta ?: null,
            'client_uuid' => (string) Str::uuid(),
        ])['message'];
    }

    /**
     * Post an entity reference card (approval, ticket...). If $senderId is given the
     * sender must be an active participant; otherwise it is a system-originated card.
     */
    public function postEntityMessage(int $conversationId, string $entityType, int $entityId, ?int $senderId = null, ?string $body = null): Message
    {
        $conversation = $this->conversationOrFail($conversationId);

        if ($senderId !== null && ! $conversation->isActiveParticipant($senderId)) {
            throw MessagingException::notParticipant();
        }

        return $this->createMessage($conversation, $senderId, [
            'type' => Message::TYPE_ENTITY_REF,
            'body' => $body,
            'meta' => ['entity_type' => $entityType, 'entity_id' => $entityId],
            'client_uuid' => (string) Str::uuid(),
        ])['message'];
    }

    /**
     * Send a user message (idempotent on client_uuid).
     *
     * @param  array{client_uuid: string, type: string, body?: ?string, reply_to_id?: ?int, meta?: ?array}  $data
     * @return array{message: Message, created: bool}
     */
    public function sendUserMessage(Conversation $conversation, int $senderId, array $data): array
    {
        if ($existing = $this->existingForClientUuid($conversation, $senderId, $data['client_uuid'])) {
            return ['message' => $existing, 'created' => false];
        }

        $type = $data['type'];
        $meta = $data['meta'] ?? null;

        if ($type === Message::TYPE_ENTITY_REF) {
            $entityType = (string) ($meta['entity_type'] ?? '');
            $entityId = (int) ($meta['entity_id'] ?? 0);
            if (! $this->entities->canView($senderId, $entityType, $entityId)) {
                throw MessagingException::forbidden('You cannot share this item.');
            }
            $meta = ['entity_type' => $entityType, 'entity_id' => $entityId] + $meta;
        }

        return $this->createMessage($conversation, $senderId, [
            'type' => $type,
            'body' => $data['body'] ?? null,
            'meta' => $meta ?: null,
            'client_uuid' => $data['client_uuid'],
            'reply_to_id' => $data['reply_to_id'] ?? null,
        ]);
    }

    /**
     * Returns an already-stored message for this client_uuid (idempotent retry), or
     * null. A uuid used by another sender/conversation is a validation error.
     */
    public function existingForClientUuid(Conversation $conversation, int $senderId, string $clientUuid): ?Message
    {
        $existing = Message::query()->where('client_uuid', $clientUuid)->first();
        if (! $existing) {
            return null;
        }
        if ((int) $existing->conversation_id !== (int) $conversation->id || (int) $existing->sender_id !== $senderId) {
            throw MessagingException::validation(['client_uuid' => ['The client uuid has already been used.']]);
        }

        return $existing->load(Message::resourceRelations());
    }

    /**
     * Core write path. Inserts the message, advances the conversation's
     * last_message_id/last_activity_at and the sender's read pointer in one
     * transaction, then (after commit) broadcasts and queues push.
     *
     * @param  Closure(Message): void|null  $afterInsert  e.g. attachment rows, runs in the transaction
     * @return array{message: Message, created: bool}
     */
    public function createMessage(Conversation $conversation, ?int $senderId, array $attributes, ?Closure $afterInsert = null): array
    {
        if (! empty($attributes['reply_to_id'])) {
            $valid = Message::query()->whereKey($attributes['reply_to_id'])
                ->where('conversation_id', $conversation->id)->exists();
            if (! $valid) {
                throw MessagingException::validation(['reply_to_id' => ['The replied message does not belong to this conversation.']]);
            }
        }

        try {
            $message = DB::transaction(function () use ($conversation, $senderId, $attributes, $afterInsert) {
                $now = now();
                /** @var Message $message */
                $message = Message::query()->create($attributes + [
                    'conversation_id' => $conversation->id,
                    'sender_id' => $senderId,
                    'created_at' => $now,
                ]);

                if ($afterInsert) {
                    $afterInsert($message);
                }

                Conversation::query()->whereKey($conversation->id)
                    ->where(fn ($q) => $q->whereNull('last_message_id')->orWhere('last_message_id', '<', $message->id))
                    ->update(['last_message_id' => $message->id, 'last_activity_at' => $now, 'updated_at' => $now]);

                if ($senderId !== null) {
                    $this->advanceReadPointer($conversation->id, $senderId, $message->id);
                }

                return $message;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent retry with the same client_uuid won the race.
            if ($senderId !== null && ($existing = $this->existingForClientUuid($conversation, $senderId, $attributes['client_uuid']))) {
                return ['message' => $existing, 'created' => false];
            }
            throw $e;
        }

        $message->load(Message::resourceRelations());
        $this->broadcastNewMessage($conversation, $message);

        return ['message' => $message, 'created' => true];
    }

    public function editMessage(Message $message, int $userId, string $body): Message
    {
        $this->assertOwnLiveMessage($message, $userId);
        if ($message->type !== Message::TYPE_TEXT) {
            throw MessagingException::forbidden('Only text messages can be edited.');
        }

        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
        $message->load(Message::resourceRelations());

        Realtime::dispatch(new MessageEdited((int) $message->conversation_id, $this->render($message)));

        return $message;
    }

    public function deleteMessage(Message $message, int $userId): Message
    {
        $this->assertOwnLiveMessage($message, $userId);

        $message->forceFill(['deleted_at' => now()])->save();
        $message->load(Message::resourceRelations());

        Realtime::dispatch(new MessageDeleted((int) $message->conversation_id, $this->render($message)));

        return $message;
    }

    /**
     * Move the read pointer forward (never backwards).
     *
     * @return array{conversation_id: int, last_read_message_id: ?int, unread_count: int}
     */
    public function markRead(Conversation $conversation, int $userId, int $lastReadMessageId): array
    {
        // Never point past the newest message of the conversation.
        $target = min($lastReadMessageId, (int) $conversation->last_message_id);

        $moved = $target > 0 && $this->advanceReadPointer($conversation->id, $userId, $target) > 0;

        $current = Participant::query()->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)->value('last_read_message_id');
        $current = $current !== null ? (int) $current : null;
        $unread = $this->unreadCount($conversation->id, $userId, $current);

        if ($moved && $current !== null) {
            Realtime::dispatch(
                new ReadReceiptUpdated((int) $conversation->id, $userId, $current),
                new ConversationRead($userId, (int) $conversation->id, $current, $unread),
            );
        }

        return [
            'conversation_id' => (int) $conversation->id,
            'last_read_message_id' => $current,
            'unread_count' => $unread,
        ];
    }

    /**
     * Move the delivered pointer forward (never backwards). Broadcasts `delivered.updated`
     * only when it actually advances.
     *
     * @return array{conversation_id: int, user_id: int, last_delivered_message_id: ?int}
     */
    public function markDelivered(Conversation $conversation, int $userId, int $lastDeliveredMessageId): array
    {
        // Never point past the newest message of the conversation.
        $target = min($lastDeliveredMessageId, (int) $conversation->last_message_id);

        $moved = $target > 0 && $this->advanceDeliveredPointer($conversation->id, $userId, $target) > 0;

        $current = Participant::query()->where('conversation_id', $conversation->id)
            ->where('user_id', $userId)->value('last_delivered_message_id');
        $current = $current !== null ? (int) $current : null;

        if ($moved && $current !== null) {
            Realtime::dispatch(new DeliveryReceiptUpdated((int) $conversation->id, $userId, $current));
        }

        return [
            'conversation_id' => (int) $conversation->id,
            'user_id' => $userId,
            'last_delivered_message_id' => $current,
        ];
    }

    public function unreadCount(int $conversationId, int $userId, ?int $lastRead): int
    {
        return Message::query()
            ->where('conversation_id', $conversationId)
            ->where('id', '>', $lastRead ?? 0)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('sender_id')->orWhere('sender_id', '<>', $userId))
            ->count();
    }

    /**
     * Unread counts for all of the user's conversations in one grouped query.
     *
     * @return array{total: int, conversations: array<int, array{conversation_id: int, unread_count: int}>}
     */
    public function unreadSummary(int $userId): array
    {
        $grammar = (new Participant)->getConnection()->getQueryGrammar();

        $rows = Participant::query()->from('msg_participants as p')
            ->join('msg_messages as m', function ($j) use ($userId, $grammar) {
                $j->on('m.conversation_id', '=', 'p.conversation_id')
                    ->whereRaw($grammar->wrap('m.id').' > coalesce('.$grammar->wrap('p.last_read_message_id').', 0)')
                    ->whereNull('m.deleted_at')
                    ->where(fn ($q) => $q->whereNull('m.sender_id')->orWhere('m.sender_id', '<>', $userId));
            })
            ->where('p.user_id', $userId)
            ->whereNull('p.left_at')
            ->groupBy('p.conversation_id')
            ->orderBy('p.conversation_id')
            ->toBase()
            ->select('p.conversation_id')
            ->selectRaw('count(*) as unread_count')
            ->get();

        $items = $rows->map(fn ($r) => [
            'conversation_id' => (int) $r->conversation_id,
            'unread_count' => (int) $r->unread_count,
        ])->values()->all();

        return ['total' => array_sum(array_column($items, 'unread_count')), 'conversations' => $items];
    }

    public function render(Message $message): array
    {
        return (new MessageResource($message))->resolve(request());
    }

    /**
     * Atomically move a participant's read pointer forward; returns affected rows (0 = unchanged).
     * Also raises the delivered pointer to at least the read id (read implies delivered).
     */
    public function advanceReadPointer(int $conversationId, int $userId, int $messageId): int
    {
        $delivered = (new Participant)->getConnection()->getQueryGrammar()->wrap('last_delivered_message_id');

        return Participant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->where(fn ($q) => $q->whereNull('last_read_message_id')->orWhere('last_read_message_id', '<', $messageId))
            ->update([
                'last_read_message_id' => $messageId,
                'last_delivered_message_id' => DB::raw(
                    "case when {$delivered} is null or {$delivered} < {$messageId} then {$messageId} else {$delivered} end"
                ),
            ]);
    }

    /** Atomically move a participant's delivered pointer forward; returns affected rows (0 = unchanged). */
    public function advanceDeliveredPointer(int $conversationId, int $userId, int $messageId): int
    {
        return Participant::query()
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->where(fn ($q) => $q->whereNull('last_delivered_message_id')->orWhere('last_delivered_message_id', '<', $messageId))
            ->update(['last_delivered_message_id' => $messageId]);
    }

    private function broadcastNewMessage(Conversation $conversation, Message $message): void
    {
        $recipients = $conversation->activeParticipantIds();

        Realtime::dispatch(
            new MessageSent((int) $conversation->id, $this->render($message)),
            new UserMessageCreated($recipients, [
                'conversation_id' => (int) $conversation->id,
                'message_id' => (int) $message->id,
                'sender_id' => $message->sender_id !== null ? (int) $message->sender_id : null,
                'type' => $message->type,
                'preview' => MessagePreview::for($message),
                'client_uuid' => $message->client_uuid,
                'created_at' => $message->created_at?->toIso8601ZuluString(),
            ]),
        );

        $job = new SendPushJob((int) $message->id);
        if ($connection = config('messaging.push.connection')) {
            $job->onConnection($connection);
        }
        dispatch($job->onQueue(config('messaging.push.queue', 'default'))->afterCommit());
    }

    private function assertOwnLiveMessage(Message $message, int $userId): void
    {
        if ($message->sender_id === null || (int) $message->sender_id !== $userId) {
            throw MessagingException::forbidden('You can only change your own messages.');
        }
        if ($message->isDeleted()) {
            throw MessagingException::forbidden('This message has been deleted.');
        }
    }

    private function conversationOrFail(int $conversationId): Conversation
    {
        return Conversation::query()->find($conversationId) ?? throw MessagingException::notFound('Conversation not found.');
    }
}
