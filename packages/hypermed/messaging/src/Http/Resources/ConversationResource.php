<?php

namespace Hypermed\Messaging\Http\Resources;

use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\UserSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Renders a Conversation hydrated by Services\ConversationQuery, which sets the
 * viewer-specific attributes (my_role, my_last_read_message_id, my_muted_until,
 * unread_count, participant_count) and the relations lastMessage and
 * participantsPreview (Participant models with `user` loaded).
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Conversation $c */
        $c = $this->resource;
        $last = $c->relationLoaded('lastMessage') ? $c->getRelation('lastMessage') : null;
        $participants = $c->relationLoaded('participantsPreview') ? $c->getRelation('participantsPreview') : collect();
        $muted = $c->getAttribute('my_muted_until');
        $myLastRead = $c->getAttribute('my_last_read_message_id');

        return [
            'id' => (int) $c->id,
            'type' => $c->type,
            'title' => $c->title,
            'org_unit_key' => $c->org_unit_key,
            'entity_type' => $c->entity_type,
            'entity_id' => $c->entity_id !== null ? (int) $c->entity_id : null,
            'created_by' => $c->created_by !== null ? (int) $c->created_by : null,
            'created_at' => $c->created_at?->toIso8601ZuluString(),
            'last_activity_at' => ($c->last_activity_at ?? $c->created_at)?->toIso8601ZuluString(),
            'last_message' => $last ? (new MessageResource($last))->resolve($request) : null,
            'unread_count' => (int) $c->getAttribute('unread_count'),
            'my_role' => $c->getAttribute('my_role') ?? Participant::ROLE_MEMBER,
            'last_read_message_id' => $myLastRead !== null ? (int) $myLastRead : null,
            'muted_until' => $muted ? Carbon::parse($muted)->toIso8601ZuluString() : null,
            'participant_count' => (int) $c->getAttribute('participant_count'),
            'participants' => $participants->map(fn (Participant $p) => self::participant($p))->values()->all(),
        ];
    }

    public static function participant(Participant $p): array
    {
        $user = $p->relationLoaded('user') ? $p->getRelation('user') : null;
        $summary = $user ? UserSummary::make($user) : UserSummary::unknown((int) $p->user_id);

        return $summary + [
            'role' => $p->role,
            'last_read_message_id' => $p->last_read_message_id !== null ? (int) $p->last_read_message_id : null,
            'last_delivered_message_id' => $p->last_delivered_message_id !== null ? (int) $p->last_delivered_message_id : null,
        ];
    }
}
