<?php

namespace Hypermed\Messaging\Http\Resources;

use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\MessageRevision;
use Illuminate\Http\Request;

/**
 * A message as chat review sees it: deleted messages keep their text, and
 * edited ones list what they said before. Requires `revisions` loaded on top
 * of Message::resourceRelations().
 */
class AuditMessageResource extends MessageResource
{
    protected bool $revealDeleted = true;

    public function toArray(Request $request): array
    {
        /** @var Message $m */
        $m = $this->resource;
        $revisions = $m->relationLoaded('revisions') ? $m->getRelation('revisions') : collect();

        return parent::toArray($request) + [
            'deleted_at' => $m->deleted_at?->toIso8601ZuluString(),
            'revisions' => $revisions->map(fn (MessageRevision $r) => [
                'body' => $r->body,
                'replaced_at' => $r->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ];
    }
}
