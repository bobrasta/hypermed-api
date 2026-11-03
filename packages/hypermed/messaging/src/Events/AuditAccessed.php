<?php

namespace Hypermed\Messaging\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone with chat-review rights read chats they are not in. Not broadcast;
 * the host listens and writes it to its audit log.
 *
 * action: 'conversations' (listed a user's chats) or 'messages' (read one chat).
 */
class AuditAccessed
{
    use Dispatchable;

    /** @param array<int, int> $participantIds */
    public function __construct(
        public readonly int $viewerId,
        public readonly string $action,
        public readonly ?int $subjectUserId = null,
        public readonly ?int $conversationId = null,
        public readonly array $participantIds = [],
    ) {}
}
