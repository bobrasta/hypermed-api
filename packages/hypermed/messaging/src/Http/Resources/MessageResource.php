<?php

namespace Hypermed\Messaging\Http\Resources;

use Hypermed\Messaging\Contracts\EntityLinkResolver;
use Hypermed\Messaging\Models\Attachment;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Services\AttachmentService;
use Hypermed\Messaging\Support\MessagePreview;
use Hypermed\Messaging\Support\MessagingConfig;
use Hypermed\Messaging\Support\UserSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Requires relations sender, attachments and replyTo.sender to be loaded
 * (see Message::resourceRelations()); it never lazy-loads.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        /** @var Message $m */
        $m = $this->resource;
        $deleted = $m->isDeleted();

        return [
            'id' => (int) $m->id,
            'conversation_id' => (int) $m->conversation_id,
            'sender_id' => $m->sender_id !== null ? (int) $m->sender_id : null,
            'sender' => $this->senderSummary($m),
            'type' => $m->type,
            'body' => $deleted ? null : $m->body,
            'meta' => $deleted ? null : ($m->meta ?: null),
            'client_uuid' => $m->client_uuid,
            'reply_to_id' => $m->reply_to_id !== null ? (int) $m->reply_to_id : null,
            'reply_to' => $this->replyTo($m),
            'attachments' => $deleted ? [] : $this->attachments($m),
            'entity' => $deleted ? null : $this->entity($m),
            'is_deleted' => $deleted,
            'edited_at' => $m->edited_at?->toIso8601ZuluString(),
            'created_at' => $m->created_at?->toIso8601ZuluString(),
        ];
    }

    private function senderSummary(Message $m): ?array
    {
        if ($m->sender_id === null) {
            return null;
        }
        $sender = $m->relationLoaded('sender') ? $m->getRelation('sender') : null;

        return $sender ? UserSummary::make($sender) : UserSummary::unknown((int) $m->sender_id);
    }

    private function replyTo(Message $m): ?array
    {
        if ($m->reply_to_id === null) {
            return null;
        }
        /** @var Message|null $r */
        $r = $m->relationLoaded('replyTo') ? $m->getRelation('replyTo') : null;
        if (! $r) {
            return null;
        }
        $rDeleted = $r->isDeleted();
        $sender = $r->relationLoaded('sender') ? $r->getRelation('sender') : null;

        return [
            'id' => (int) $r->id,
            'sender_id' => $r->sender_id !== null ? (int) $r->sender_id : null,
            'sender_name' => $r->sender_id === null ? null
                : ($sender ? (string) $sender->getAttribute(MessagingConfig::nameColumn()) : 'Unknown user'),
            'type' => $r->type,
            'body' => $rDeleted || $r->body === null ? null : MessagePreview::truncate($r->body),
            'is_deleted' => $rDeleted,
        ];
    }

    private function attachments(Message $m): array
    {
        if (! $m->relationLoaded('attachments')) {
            return [];
        }
        $service = app(AttachmentService::class);

        return $m->getRelation('attachments')->map(fn (Attachment $a) => [
            'id' => (int) $a->id,
            'mime' => $a->mime,
            'size' => (int) $a->size,
            'original_name' => $a->original_name,
            'is_image' => $a->isImage(),
            'url' => $service->signedUrl($a),
            'thumb_url' => $a->thumb_path ? $service->signedUrl($a, true) : null,
        ])->values()->all();
    }

    private function entity(Message $m): ?array
    {
        if ($m->type !== Message::TYPE_ENTITY_REF) {
            return null;
        }
        $type = (string) ($m->meta['entity_type'] ?? '');
        $id = (int) ($m->meta['entity_id'] ?? 0);
        if ($type === '' || $id <= 0) {
            return null;
        }
        $data = app(EntityLinkResolver::class)->resolve($type, $id) ?? [];

        return [
            'type' => $type,
            'id' => $id,
            'title' => $data['title'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'url' => $data['url'] ?? null,
            'status' => $data['status'] ?? null,
        ];
    }
}
