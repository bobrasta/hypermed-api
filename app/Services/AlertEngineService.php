<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\Contract;
use App\Models\HrSetting;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Generic "a date field that needs a reminder before it arrives" engine —
 * one config array of watchers instead of a one-off notifier per feature.
 * Adding a future watcher (NIDA renewal, certification expiry) is one
 * config entry + one `..._notified_at` column on that model, nothing else.
 * Dedup follows the same `..._notified_at` timestamp pattern already
 * proven for `inventory_items.reorder_notified_at`.
 */
class AlertEngineService
{
    /** @return array<int, array{model: class-string, dateColumn: string, notifiedAtColumn: string, notifyRoles: array<int, string>, label: string}> */
    private function watchers(): array
    {
        return [
            [
                'model'            => Contract::class,
                'dateColumn'       => 'end_date',
                'notifiedAtColumn' => 'expiry_notified_at',
                'notifyRoles'      => ['hr', 'admin'],
                'label'            => 'Contract expiring',
            ],
            [
                'model'            => Contract::class,
                'dateColumn'       => 'probation_end_date',
                'notifiedAtColumn' => 'probation_notified_at',
                'notifyRoles'      => ['hr', 'admin'],
                'label'            => 'Probation period ending',
            ],
        ];
    }

    public function checkAll(): int
    {
        $leadDays = (int) HrSetting::get('reminder_lead_days', '30');
        $sent = 0;

        foreach ($this->watchers() as $watcher) {
            $sent += $this->checkWatcher($watcher, $leadDays);
        }

        return $sent;
    }

    private function checkWatcher(array $watcher, int $leadDays): int
    {
        $model = $watcher['model'];
        $dateColumn = $watcher['dateColumn'];
        $notifiedAtColumn = $watcher['notifiedAtColumn'];
        $threshold = Carbon::today()->addDays($leadDays);

        $due = $model::query()
            ->where('status', 'active')
            ->whereNotNull($dateColumn)
            ->whereNull($notifiedAtColumn)
            ->whereDate($dateColumn, '<=', $threshold)
            ->whereDate($dateColumn, '>=', Carbon::today())
            ->get();

        $sent = 0;
        foreach ($due as $record) {
            $staffName = $record->user?->name ?? 'A staff member';
            $dueDate = $record->{$dateColumn};

            User::whereIn('role', $watcher['notifyRoles'])
                ->pluck('id')
                ->each(fn ($id) => AppNotification::create([
                    'user_id'     => $id,
                    'type'        => 'hr_alert',
                    'title'       => $watcher['label'],
                    'body'        => "{$watcher['label']} for {$staffName} on {$dueDate?->toDateString()}.",
                    'entity_type' => 'contract',
                    'entity_id'   => $record->id,
                    'is_read'     => false,
                ]));

            $record->update([$notifiedAtColumn => now()]);
            $sent++;
        }

        return $sent;
    }
}
