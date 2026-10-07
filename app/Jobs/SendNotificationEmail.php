<?php

namespace App\Jobs;

use App\Models\AppNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Emails one in-app notification to its recipient. Queued (the VPS runs a
 * queue worker), so a slow mail server never slows down the action that
 * raised the notification.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $n = AppNotification::with('user:id,name,email,is_active')->find($this->notificationId);
        $user = $n?->user;
        if (! $n || ! $user || ! $user->is_active || ! self::deliverable($user->email)) {
            return;
        }

        Mail::send('mail.notification', [
            'name' => $user->name,
            'title' => $n->title,
            'body' => $n->body,
            // hypermed-web marks it read and sends the user to the right
            // page for its type and their role (NotificationRoute).
            'url' => config('notification_mail.web_url') . "/notifications/{$n->id}/open",
        ], function ($m) use ($user, $n) {
            $m->to($user->email, $user->name)->subject($n->title);
        });
    }

    /** Whether notifications of this type also go out by email. */
    public static function wanted(string $type): bool
    {
        $types = config('notification_mail.types');

        return (in_array('*', $types, true) || in_array($type, $types, true))
            && ! in_array($type, config('notification_mail.exclude_types', []), true);
    }

    public static function deliverable(?string $email): bool
    {
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return ! in_array($domain, array_map('strtolower', config('notification_mail.skip_domains')), true);
    }
}
