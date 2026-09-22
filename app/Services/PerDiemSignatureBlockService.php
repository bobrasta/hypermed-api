<?php

namespace App\Services;

use App\Models\PerDiemRequest;
use App\Models\Setting;

/**
 * Section 15.6: the template's four sign-off lines (Prepared by,
 * Reviewed by/Technical supervisor, Finance, Approved by/Managing
 * director) don't map 1:1 onto this app's real approval chain (Team
 * Lead -> CTO -> Accountant-initiate -> Accountant/Director final
 * release — see PerDiemController), so this builds the block FROM the
 * plan's own real actor/timestamp columns instead of hardcoding names.
 * The CTO stage becomes a fifth, additional "Reviewed by" line per the
 * spec's own allowance ("if a CTO stage exists ... show it as an
 * additional line so no real approval is missing"). The final
 * "Approved by / Managing director" line reflects whoever actually
 * released the funds (paid_by/paid_at) since the real workflow has no
 * separate director-only action distinct from that release — markPaid()
 * is reachable by either accountant or director authority. Role LABELS
 * (not which column feeds which line — that's structural) are
 * adjustable via the `per_diem_signature_role_labels` Setting rather
 * than hardcoded, per the spec's "adjust in a Settings screen" ask.
 */
class PerDiemSignatureBlockService
{
    private const DEFAULT_LABELS = [
        'team_lead' => 'Technical supervisor',
        'cto' => 'CTO',
        'accountant_initiate' => 'Finance',
        'final_release' => 'Managing director',
    ];

    public function build(PerDiemRequest $plan): array
    {
        $labels = array_merge(self::DEFAULT_LABELS, $this->configuredLabels());

        return [
            $this->line('prepared_by', 'Prepared by', $plan->staff_name_snapshot ?? $plan->user?->name, $plan->created_at),
            $this->line('team_lead', $labels['team_lead'], $plan->teamLeadReviewer?->name, $plan->team_lead_reviewed_at),
            $this->line('cto', $labels['cto'], $plan->reviewer?->name, $plan->reviewed_at),
            $this->line('accountant_initiate', $labels['accountant_initiate'], $plan->paymentInitiatedBy?->name, $plan->payment_initiated_at),
            $this->line('final_release', $labels['final_release'], $plan->paidBy?->name, $plan->paid_at),
        ];
    }

    private function configuredLabels(): array
    {
        $raw = Setting::get('per_diem_signature_role_labels');
        $decoded = $raw ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function line(string $stage, string $roleLabel, ?string $personName, $actedAt): array
    {
        return [
            'stage' => $stage,
            'role_label' => $roleLabel,
            'person_name' => $personName,
            'acted_at' => $actedAt?->toIso8601String(),
            // Africa/Dar_es_Salaam per Section 0's binding display rule.
            'acted_at_display' => $actedAt?->timezone('Africa/Dar_es_Salaam')->format('d/m/Y H:i'),
            'pending' => $actedAt === null,
        ];
    }
}
