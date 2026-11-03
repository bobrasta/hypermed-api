<?php

namespace Hypermed\Messaging\Jobs;

use Hypermed\Messaging\Contracts\PresenceChecker;
use Hypermed\Messaging\Contracts\PushNotifier;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Support\MessagePreview;
use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queued: pushes a high-priority data message to participants who are offline
 * (PresenceChecker) and not muted, excluding the sender.
 */
class SendPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 30];

    public function __construct(public readonly int $messageId) {}

    public function handle(PushNotifier $notifier, PresenceChecker $presence): void
    {
        $message = Message::query()->with(['conversation', 'sender', 'attachments'])->find($this->messageId);
        if (! $message || $message->isDeleted() || ! $message->conversation) {
            return;
        }

        $now = now();
        $recipients = Participant::query()
            ->where('conversation_id', $message->conversation_id)
            ->whereNull('left_at')
            ->when($message->sender_id !== null, fn ($q) => $q->where('user_id', '<>', $message->sender_id))
            ->where(fn ($q) => $q->whereNull('muted_until')->orWhere('muted_until', '<=', $now))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => ! $presence->isOnline($id))
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $conversation = $message->conversation;
        $senderName = $message->sender
            ? (string) $message->sender->getAttribute(MessagingConfig::nameColumn())
            : '';

        $notifier->send($recipients, [
            'kind' => 'message',
            'conversation_id' => (string) $message->conversation_id,
            'message_id' => (string) $message->id,
            'sender_name' => $senderName,
            'preview' => MessagePreview::for($message),
            'type' => (string) $message->type,
            'conversation_type' => (string) $conversation->type,
            'conversation_title' => (string) ($conversation->title ?? ''),
        ]);
    }
}
