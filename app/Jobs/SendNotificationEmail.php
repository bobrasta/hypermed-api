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

    /** Web-app paths for the entity types that have a page (hypermed-web's NotificationRoute). */
    private const PATHS = [
        'shipment' => '/shipments/%d',
        'tender' => '/tenders/%d',
        'device_registration' => '/device-registrations/%d',
    ];

    public function handle(): void
    {
        $n = AppNotification::with('user:id,name,email,is_active')->find($this->notificationId);
        $user = $n?->user;
        if (! $n || ! $user || ! $user->is_active || ! self::deliverable($user->email)) {
            return;
        }

        $path = isset(self::PATHS[$n->entity_type]) && $n->entity_id
            ? sprintf(self::PATHS[$n->entity_type], $n->entity_id)
            : '/notifications';

        Mail::send('mail.notification', [
            'name' => $user->name,
            'title' => $n->title,
            'body' => $n->body,
            'url' => config('notification_mail.web_url') . $path,
        ], function ($m) use ($user, $n) {
            $m->to($user->email, $user->name)->subject($n->title);
        });
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
