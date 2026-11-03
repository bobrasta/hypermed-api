<?php

namespace Hypermed\Messaging\Services;

use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Models\Attachment;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads conversations for a viewer in a constant number of queries:
 *
 *  Q1  conversations ⨝ my participant row ⨝ last message ⨝ its sender ⨝ its
 *      reply-to message ⨝ that sender, plus correlated sub-selects for
 *      participant_count and my unread_count (index msg_messages(conversation_id,id)).
 *  Q2  participants ⨝ users for all conversations of the page (ROW_NUMBER()
 *      window limits groups to N participants in list mode).
 *  Q3  attachments of the last messages — only issued when at least one last
 *      message is a file message.
 *
 * Used by the list endpoint and the detail endpoint (all participants).
 */
class ConversationQuery
{
    private const LM_COLUMNS = ['id', 'conversation_id', 'sender_id', 'type', 'body', 'meta', 'client_uuid', 'reply_to_id', 'edited_at', 'deleted_at', 'created_at'];

    /**
     * @return array{items: Collection<int, Conversation>, next_cursor: ?string}
     */
    public function page(int $userId, ?string $cursor, int $limit): array
    {
        $q = $this->baseQuery($userId);

        if ($cursor !== null && $cursor !== '') {
            [$at, $id] = $this->decodeCursor($cursor);
            $q->where(function (Builder $w) use ($at, $id) {
                $w->where('c.last_activity_at', '<', $at)
                    ->orWhere(fn (Builder $x) => $x->where('c.last_activity_at', '=', $at)->where('c.id', '<', $id));
            });
        }

        $rows = $q->orderByDesc('c.last_activity_at')->orderByDesc('c.id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $items = $this->hydrate($rows, $userId, (int) config('messaging.list.group_participants_preview', 5));

        $next = null;
        if ($hasMore && $items->isNotEmpty()) {
            $last = $items->last();
            $next = $this->encodeCursor($last->last_activity_at, (int) $last->id);
        }

        return ['items' => $items, 'next_cursor' => $next];
    }

    /** Single conversation with all active participants (≤ 3 queries). */
    public function find(int $userId, int $conversationId): Conversation
    {
        $rows = $this->baseQuery($userId)->where('c.id', $conversationId)->get();

        if ($rows->isEmpty()) {
            throw Conversation::query()->whereKey($conversationId)->exists()
                ? MessagingException::notParticipant()
                : MessagingException::notFound('Conversation not found.');
        }

        return $this->hydrate($rows, $userId, null)->first();
    }

    private function connection(): ConnectionInterface
    {
        return (new Conversation)->getConnection();
    }

    private function baseQuery(int $userId): Builder
    {
        $conn = $this->connection();
        $grammar = $conn->getQueryGrammar();
        $userTable = MessagingConfig::userTable();
        $userKey = MessagingConfig::userKey();
        $nameCol = MessagingConfig::nameColumn();
        $avatarCol = MessagingConfig::avatarColumn();

        $q = $conn->table('msg_conversations as c')
            ->join('msg_participants as me', function ($j) use ($userId) {
                $j->on('me.conversation_id', '=', 'c.id')
                    ->where('me.user_id', '=', $userId)
                    ->whereNull('me.left_at');
            })
            ->leftJoin('msg_messages as lm', 'lm.id', '=', 'c.last_message_id')
            ->leftJoin($userTable.' as lmu', 'lmu.'.$userKey, '=', 'lm.sender_id')
            ->leftJoin('msg_messages as rm', 'rm.id', '=', 'lm.reply_to_id')
            ->leftJoin($userTable.' as rmu', 'rmu.'.$userKey, '=', 'rm.sender_id')
            ->select('c.*')
            ->addSelect([
                'me.role as my_role',
                'me.last_read_message_id as my_last_read_message_id',
                'me.muted_until as my_muted_until',
                'lmu.'.$nameCol.' as lmu_name',
                'rm.id as rm_id',
                'rm.sender_id as rm_sender_id',
                'rm.type as rm_type',
                'rm.body as rm_body',
                'rm.deleted_at as rm_deleted_at',
                'rmu.'.$nameCol.' as rmu_name',
            ]);

        foreach (self::LM_COLUMNS as $col) {
            $q->addSelect('lm.'.$col.' as lm_'.$col);
        }
        if ($avatarCol) {
            $q->addSelect('lmu.'.$avatarCol.' as lmu_avatar');
        }

        $q->selectSub(function (Builder $s) {
            $s->from('msg_participants as pc')
                ->selectRaw('count(*)')
                ->whereColumn('pc.conversation_id', 'c.id')
                ->whereNull('pc.left_at');
        }, 'participant_count');

        $q->selectSub(function (Builder $s) use ($userId, $grammar) {
            $s->from('msg_messages as um')
                ->selectRaw('count(*)')
                ->whereColumn('um.conversation_id', 'c.id')
                ->whereRaw($grammar->wrap('um.id').' > coalesce('.$grammar->wrap('me.last_read_message_id').', 0)')
                ->whereNull('um.deleted_at')
                ->where(fn (Builder $x) => $x->whereNull('um.sender_id')->orWhere('um.sender_id', '<>', $userId));
        }, 'unread_count');

        return $q;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, Conversation>
     */
    private function hydrate(Collection $rows, int $userId, ?int $participantLimit): Collection
    {
        if ($rows->isEmpty()) {
            return collect();
        }

        $userClass = MessagingConfig::userModel();
        $userKey = MessagingConfig::userKey();
        $nameCol = MessagingConfig::nameColumn();
        $avatarCol = MessagingConfig::avatarColumn();
        $makeUser = function (int $id, $name, $avatar) use ($userClass, $userKey, $nameCol, $avatarCol) {
            $attrs = [$userKey => $id, $nameCol => $name];
            if ($avatarCol) {
                $attrs[$avatarCol] = $avatar;
            }

            return (new $userClass)->newFromBuilder($attrs);
        };

        $conversations = collect();
        $lastMessages = [];

        foreach ($rows as $row) {
            $row = (array) $row;
            $convAttrs = [];
            $lm = [];
            foreach ($row as $k => $v) {
                if (str_starts_with($k, 'lm_')) {
                    $lm[substr($k, 3)] = $v;
                } elseif (! str_starts_with($k, 'lmu_') && ! str_starts_with($k, 'rm_') && ! str_starts_with($k, 'rmu_')) {
                    $convAttrs[$k] = $v;
                }
            }

            /** @var Conversation $conv */
            $conv = (new Conversation)->newFromBuilder($convAttrs);
            $conv->setRelation('lastMessage', null);

            if ($lm['id'] !== null) {
                /** @var Message $message */
                $message = (new Message)->newFromBuilder($lm);
                $message->setRelation('sender', $message->sender_id !== null && $row['lmu_name'] !== null
                    ? $makeUser((int) $message->sender_id, $row['lmu_name'], $row['lmu_avatar'] ?? null)
                    : null);
                $message->setRelation('attachments', new \Illuminate\Database\Eloquent\Collection);

                $reply = null;
                if ($row['rm_id'] !== null) {
                    $reply = (new Message)->newFromBuilder([
                        'id' => $row['rm_id'],
                        'conversation_id' => $conv->id,
                        'sender_id' => $row['rm_sender_id'],
                        'type' => $row['rm_type'],
                        'body' => $row['rm_body'],
                        'deleted_at' => $row['rm_deleted_at'],
                    ]);
                    $reply->setRelation('sender', $row['rm_sender_id'] !== null && $row['rmu_name'] !== null
                        ? $makeUser((int) $row['rm_sender_id'], $row['rmu_name'], null)
                        : null);
                }
                $message->setRelation('replyTo', $reply);

                $conv->setRelation('lastMessage', $message);
                $lastMessages[$message->id] = $message;
            }

            $conversations->push($conv);
        }

        // Q2: participants + user summaries.
        $ids = $conversations->pluck('id')->all();
        $participants = $this->participantRows($ids, $participantLimit);
        $byConversation = [];
        foreach ($participants as $p) {
            $p = (array) $p;
            /** @var Participant $model */
            $model = (new Participant)->newFromBuilder([
                'id' => $p['id'],
                'conversation_id' => $p['conversation_id'],
                'user_id' => $p['user_id'],
                'role' => $p['role'],
                'last_read_message_id' => $p['last_read_message_id'],
                'last_delivered_message_id' => $p['last_delivered_message_id'],
                'joined_at' => $p['joined_at'],
                'left_at' => null,
            ]);
            $model->setRelation('user', $p['u_name'] !== null
                ? $makeUser((int) $p['user_id'], $p['u_name'], $p['u_avatar'] ?? null)
                : null);
            $byConversation[(int) $p['conversation_id']][] = $model;
        }
        foreach ($conversations as $conv) {
            $conv->setRelation('participantsPreview', new \Illuminate\Database\Eloquent\Collection($byConversation[$conv->id] ?? []));
        }

        // Q3: attachments for file-type last messages only.
        $fileMessageIds = collect($lastMessages)
            ->filter(fn (Message $m) => $m->type === Message::TYPE_FILE && ! $m->isDeleted())
            ->keys()->all();
        if ($fileMessageIds !== []) {
            $attachments = Attachment::query()->whereIn('message_id', $fileMessageIds)->orderBy('id')->get()->groupBy('message_id');
            foreach ($fileMessageIds as $mid) {
                $lastMessages[$mid]->setRelation('attachments', $attachments->get($mid) ?? new \Illuminate\Database\Eloquent\Collection);
            }
        }

        return $conversations;
    }

    /** @param array<int,int> $conversationIds */
    private function participantRows(array $conversationIds, ?int $limit): Collection
    {
        $conn = $this->connection();
        $grammar = $conn->getQueryGrammar();
        $userTable = MessagingConfig::userTable();
        $userKey = MessagingConfig::userKey();
        $nameCol = MessagingConfig::nameColumn();
        $avatarCol = MessagingConfig::avatarColumn();

        $q = $conn->table('msg_participants as p')
            ->leftJoin($userTable.' as u', 'u.'.$userKey, '=', 'p.user_id')
            ->select(['p.id', 'p.conversation_id', 'p.user_id', 'p.role', 'p.last_read_message_id', 'p.last_delivered_message_id', 'p.joined_at', 'u.'.$nameCol.' as u_name'])
            ->whereIn('p.conversation_id', $conversationIds)
            ->whereNull('p.left_at');
        if ($avatarCol) {
            $q->addSelect('u.'.$avatarCol.' as u_avatar');
        }

        if ($limit === null) {
            return $q->orderBy('p.conversation_id')->orderBy('p.joined_at')->orderBy('p.id')->get();
        }

        $q->selectRaw('row_number() over (partition by '.$grammar->wrap('p.conversation_id')
            .' order by '.$grammar->wrap('p.joined_at').', '.$grammar->wrap('p.id').') as rn');

        return $conn->query()->fromSub($q, 'x')
            ->where('rn', '<=', $limit)
            ->orderBy('conversation_id')->orderBy('rn')
            ->get();
    }

    private function encodeCursor(?Carbon $at, int $id): string
    {
        $payload = json_encode(['t' => ($at ?? Carbon::createFromTimestamp(0))->format('Y-m-d H:i:s'), 'id' => $id]);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: int} */
    private function decodeCursor(string $cursor): array
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json !== false ? json_decode($json, true) : null;

        if (! is_array($data) || ! isset($data['t'], $data['id']) || ! is_string($data['t']) || ! is_int($data['id'])
            || ! preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $data['t'])) {
            throw MessagingException::validation(['cursor' => ['The cursor is invalid.']]);
        }

        return [$data['t'], $data['id']];
    }
}
