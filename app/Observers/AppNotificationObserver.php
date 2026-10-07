<?php

namespace App\Observers;

use App\Jobs\SendNotificationEmail;
use App\Models\AppNotification;

/** Queues the email copy of each in-app notification whose type is configured for it. */
class AppNotificationObserver
{
    public function created(AppNotification $n): void
    {
        if (config('notification_mail.enabled') && SendNotificationEmail::wanted((string) $n->type)) {
            SendNotificationEmail::dispatch($n->id)->afterCommit();
        }
    }
}
