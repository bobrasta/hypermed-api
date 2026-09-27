<?php

namespace App\Services\Shipment;

use App\Models\AppNotification;
use App\Models\Shipment;
use App\Models\User;
use App\Services\NotificationTemplateService;

/**
 * Section 18.3: every creation and status change notifies the same
 * role-based list — CTO, MD (super_admin), Sales Manager — plus the manager
 * of the shipment's department, or the procurement lead and admin when that
 * department has no manager set. In-app only: the notification engine has
 * no email channel yet (Section 11 email is deferred).
 */
class ShipmentNotifier
{
    public const LEADERSHIP_ROLES = ['cto', 'super_admin', 'sales_manager'];
    public const FALLBACK_ROLES = ['procurement_manager', 'admin'];

    public function __construct(private ShipmentFlow $flow, private NotificationTemplateService $templates) {}

    public function recipients(Shipment $s): array
    {
        $ids = User::where('is_active', true)->whereIn('role', self::LEADERSHIP_ROLES)->pluck('id')->all();
        $manager = $s->department?->manager_id;
        $ids = array_merge($ids, $manager
            ? [$manager]
            : User::where('is_active', true)->whereIn('role', self::FALLBACK_ROLES)->pluck('id')->all());

        return array_values(array_unique($ids));
    }

    public function created(Shipment $s, User $actor): void
    {
        $this->send($s, $actor, 'shipment.created', [
            'actor' => $actor->name,
            'direction' => $s->direction,
        ]);
    }

    public function statusChanged(Shipment $s, User $actor, ?string $note): void
    {
        $this->send($s, $actor, 'shipment.status_changed', [
            'status' => $this->flow->label($s),
            'note_suffix' => $note ? " Note: {$note}" : '',
        ]);
    }

    private function send(Shipment $s, User $actor, string $key, array $vars): void
    {
        $rendered = $this->templates->render($key, $vars + [
            'reference' => $s->reference,
            'description' => $s->description,
        ]);

        foreach ($this->recipients($s) as $uid) {
            if ($uid === $actor->id) {
                continue;
            }
            AppNotification::create($rendered + [
                'user_id' => $uid,
                'entity_type' => 'shipment',
                'entity_id' => $s->id,
                'is_read' => false,
            ]);
        }
    }
}
