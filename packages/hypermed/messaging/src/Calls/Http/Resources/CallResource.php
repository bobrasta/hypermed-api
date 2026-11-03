<?php

namespace Hypermed\Messaging\Calls\Http\Resources;

use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Models\CallParticipant;
use Hypermed\Messaging\Calls\Support\CallFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Call: {id, conversation_id, initiated_by, type, status, started_at, answered_at,
 *        ended_at, end_reason, created_at, participants: [{user_id, name, status, joined_at, left_at}]}
 *
 * @mixin Call
 */
class CallResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Call $call */
        $call = $this->resource;
        $call->loadMissing('participants.user');

        return [
            'id' => (int) $call->id,
            'conversation_id' => (int) $call->conversation_id,
            'initiated_by' => (int) $call->initiated_by,
            'type' => $call->type,
            'status' => $call->status,
            'started_at' => CallFormat::iso($call->started_at),
            'answered_at' => CallFormat::iso($call->answered_at),
            'ended_at' => CallFormat::iso($call->ended_at),
            'end_reason' => $call->end_reason,
            'created_at' => CallFormat::iso($call->created_at),
            'participants' => $call->participants->map(fn (CallParticipant $p) => [
                'user_id' => (int) $p->user_id,
                'name' => CallFormat::userName($p->user),
                'status' => $p->status,
                'joined_at' => CallFormat::iso($p->joined_at),
                'left_at' => CallFormat::iso($p->left_at),
            ])->values()->all(),
        ];
    }
}
