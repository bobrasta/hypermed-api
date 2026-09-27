<?php

namespace App\Services\Tender;

use App\Models\AppNotification;
use App\Models\DeviceRegistration;
use App\Models\Tender;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Section 19.2 deadline rules — the single source of truth for both the
 * API responses (deadlines, flag, Needs Action) and the daily reminders.
 *
 *  - Bid submission: entered by hand; met once the bid is submitted.
 *  - Bid security validity: the earlier of "another tenderer was notified"
 *    and tender expiry + 28 days. Informational (it's a liability window,
 *    not a task). It stays live after a win until the contract is signed
 *    and the performance security furnished — the declaration's breach
 *    conditions cover exactly that period — and ends if the tender is lost.
 *  - Performance security: Letter of Acceptance + 14 calendar days, always
 *    computed. Highest risk (disqualification by PPRA).
 *  - Contract signing, delivery: entered by hand from the tender documents.
 *
 * Reminders go at 7 days, 3 days, on the day and once overdue, to the
 * tender owner (or every procurement manager if none) plus the Director
 * (super_admin) and CTO. Each stage is sent once per due date, so moving a
 * date re-arms its reminders.
 */
class TenderDeadlineService
{
    public const HIGH_RISK = ['performance_security', 'contract_signing'];

    private const LABELS = [
        'bid_submission' => 'Bid submission',
        'bid_security' => 'Bid security validity',
        'performance_security' => 'Performance security',
        'contract_signing' => 'Contract signing',
        'delivery' => 'Delivery',
        'registration_renewal' => 'Registration renewal',
    ];

    public function tenderExpiry(Tender $t): ?Carbon
    {
        if ($t->tender_expiry_date) {
            return $t->tender_expiry_date->copy();
        }
        if ($t->bid_submission_deadline && $t->bid_validity_days) {
            return $t->bid_submission_deadline->copy()->addDays($t->bid_validity_days);
        }

        return null;
    }

    /** @return array<int, array> ordered as shown on the tender */
    public function forTender(Tender $t, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $closed = ! $t->isOpen();
        $lost = in_array($t->status, ['lost', 'cancelled'], true);

        $expiry = $this->tenderExpiry($t);
        $bidSecurityDue = $expiry?->copy()->addDays(28);
        if ($t->other_tenderer_notified_at && (! $bidSecurityDue || $t->other_tenderer_notified_at->lt($bidSecurityDue))) {
            $bidSecurityDue = $t->other_tenderer_notified_at->copy();
        }

        $rows = [
            $this->row('bid_submission', $t->bid_submission_deadline, false, 'from the tender notice',
                met: $t->reached('bid_submitted'), void: $closed && ! $t->reached('bid_submitted'), alerts: true, today: $today),
            $this->row('bid_security', $t->reached('bid_submitted') || $lost ? $bidSecurityDue : null, true,
                $t->other_tenderer_notified_at ? 'other tenderer notified' : ($expiry ? 'tender expiry ' . $expiry->format('j M Y') . ' + 28 days' : 'needs bid validity period'),
                met: $t->reached('contract_signed'), void: $lost, alerts: false, today: $today),
            $this->row('performance_security', $t->letter_of_acceptance_date?->copy()->addDays(14), true,
                $t->letter_of_acceptance_date ? 'Letter of Acceptance ' . $t->letter_of_acceptance_date->format('j M Y') . ' + 14 days' : 'set when the Letter of Acceptance date is entered',
                met: $t->reached('performance_security_submitted') || $t->hasExecuted('performance_securing_declaration'),
                void: $closed && ! $t->reached('performance_security_submitted'), alerts: true, today: $today),
            $this->row('contract_signing', $t->contract_signing_deadline, false, 'from the tender documents',
                met: $t->reached('contract_signed'), void: $closed && ! $t->reached('contract_signed'), alerts: true, today: $today),
            $this->row('delivery', $t->delivery_deadline, false, 'from the contract',
                met: $t->reached('delivered'), void: $closed && ! $t->reached('delivered'), alerts: true, today: $today),
        ];

        return $rows;
    }

    public function forDevice(DeviceRegistration $d, ?Carbon $today = null): array
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return $this->row('registration_renewal', $d->renewal_due_date, false, 'from the registration certificate',
            met: false, void: false, alerts: true, today: $today);
    }

    private function row(string $kind, ?Carbon $due, bool $computed, string $basis, bool $met, bool $void, bool $alerts, Carbon $today): array
    {
        $days = $due ? (int) $today->diffInDays($due->copy()->startOfDay(), false) : null;
        $state = match (true) {
            $void => 'void',
            $met => 'met',
            $days === null => 'not_set',
            $days < 0 => 'overdue',
            $days === 0 => 'due_today',
            $days <= 7 => 'soon',
            default => 'ok',
        };

        return [
            'kind' => $kind,
            'label' => self::LABELS[$kind],
            'due' => $due?->toDateString(),
            'days_left' => $days,
            'computed' => $computed,
            'basis' => $basis,
            'state' => $state,
            'high_risk' => in_array($kind, self::HIGH_RISK, true),
            'alerts' => $alerts,
        ];
    }

    /** Overdue performance security or missed contract signing. */
    public function isFlagged(array $deadlines): bool
    {
        return collect($deadlines)->contains(fn ($d) => $d['high_risk'] && $d['state'] === 'overdue');
    }

    /** Most urgent open deadline, for list sorting and Needs Action. */
    public function next(array $deadlines): ?array
    {
        return collect($deadlines)
            ->filter(fn ($d) => $d['due'] && in_array($d['state'], ['overdue', 'due_today', 'soon', 'ok'], true) && $d['kind'] !== 'bid_security')
            ->sortBy('due')->first();
    }

    // ------------------------------------------------------------ reminders

    public function sendReminders(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $sent = 0;

        Tender::with(['procuringEntity', 'documents'])->whereNotIn('status', Tender::TERMINAL)->get()
            ->each(function (Tender $t) use ($today, &$sent) {
                foreach ($this->forTender($t, $today) as $d) {
                    $sent += $this->remind('Tender', $t->id, $d, $today, $this->tenderRecipients($t),
                        "Tender {$t->tender_number} — {$t->title} ({$t->procuringEntity?->name})");
                }
            });

        DeviceRegistration::whereNotNull('renewal_due_date')->get()->each(function (DeviceRegistration $r) use ($today, &$sent) {
            $sent += $this->remind('DeviceRegistration', $r->id, $this->forDevice($r, $today), $today, $this->deviceRecipients(),
                "Device registration {$r->brand_name}" . ($r->registration_number ? " ({$r->registration_number})" : ''));
        });

        return $sent;
    }

    private function remind(string $subjectType, int $subjectId, array $d, Carbon $today, array $userIds, string $context): int
    {
        if (! $d['alerts'] || ! $d['due'] || ! in_array($d['state'], ['soon', 'due_today', 'overdue'], true)) {
            return 0;
        }
        $days = $d['days_left'];
        $stage = match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'today',
            $days <= 3 => '3d',
            default => '7d',
        };

        $inserted = DB::table('deadline_alerts')->insertOrIgnore([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'kind' => $d['kind'],
            'due_date' => $d['due'], 'stage' => $stage, 'sent_at' => now(),
        ]);
        if (! $inserted) {
            return 0;
        }

        $when = match ($stage) {
            'overdue' => (-$days) . ' day' . ($days === -1 ? '' : 's') . ' overdue',
            'today' => 'due today',
            default => "due in {$days} day" . ($days === 1 ? '' : 's'),
        };
        $risk = $d['high_risk'] ? ' Missing it risks disqualification from future tenders.' : '';
        $type = $subjectType === 'DeviceRegistration' ? 'device_renewal' : ($stage === 'overdue' ? 'tender_overdue' : 'tender_deadline');

        foreach (array_unique($userIds) as $uid) {
            AppNotification::create([
                'user_id' => $uid,
                'type' => $type,
                'title' => "{$d['label']} {$when}",
                'body' => "{$context}. Due " . Carbon::parse($d['due'])->format('j M Y') . ".{$risk}",
                'entity_type' => $subjectType === 'DeviceRegistration' ? 'device_registration' : 'tender',
                'entity_id' => $subjectId,
            ]);
        }

        return 1;
    }

    private function leadership(): array
    {
        return User::where('is_active', true)->whereIn('role', ['super_admin', 'cto'])->pluck('id')->all();
    }

    private function tenderRecipients(Tender $t): array
    {
        $staff = $t->owner_id ? [$t->owner_id]
            : User::where('is_active', true)->where('role', 'procurement_manager')->pluck('id')->all();

        return array_merge($staff, $this->leadership());
    }

    private function deviceRecipients(): array
    {
        return array_merge(User::where('is_active', true)->where('role', 'procurement_manager')->pluck('id')->all(), $this->leadership());
    }
}
