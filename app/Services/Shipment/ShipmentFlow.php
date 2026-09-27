<?php

namespace App\Services\Shipment;

use App\Models\Setting;
use App\Models\Shipment;

/**
 * Section 18.2 / 18.6: the status flows, their gates and the module settings,
 * in one place so the API, the list flags and the web/Flutter UIs agree.
 *
 * Every rule below is a proposed default from the spec (18.7), NOT confirmed
 * company practice. Labels and the switchable rules live in the
 * `shipment_settings` Setting and are edited in Shipments settings; the ORDER
 * of steps is fixed, which is why shipments store the step index, not a label.
 */
class ShipmentFlow
{
    public const SETTING_KEY = 'shipment_settings';

    public const IMPORT_LABELS = [
        'Shipped', 'In transit', 'Arrived at port/airport', 'TMDA permit applied',
        'TMDA permit issued', 'Documents to clearing agent', 'Assessment received',
        'Payment made', 'Released by customs', 'Arrived at HQ',
    ];

    public const EXPORT_LABELS = ['Preparing', 'Documents sent', 'Dispatched', 'Received by recipient'];

    // Import step numbers the gates refer to.
    public const STEP_PERMIT_APPLIED = 4;
    public const STEP_PERMIT_ISSUED = 5;
    public const STEP_TO_CLEARING_AGENT = 6;
    public const STEP_ASSESSMENT = 7;
    public const STEP_PAYMENT = 8;

    public const DOC_LABELS = [
        'air_waybill' => 'Air Waybill',
        'bill_of_lading' => 'Bill of Lading',
        'packing_list' => 'Packing list',
        'commercial_invoice' => 'Commercial invoice',
        'coa' => 'Certificate of Analysis',
        'tmda_permit' => 'TMDA permit',
        'recipient_receipt' => 'Signed receipt from recipient',
    ];

    /** 18.7 — shown in settings so each can be marked confirmed once Procurement checks it. */
    public const ASSUMPTIONS = [
        'TMDA permit is issued before documents go to the clearing agent.',
        'The COA supports the TMDA application and is kept on file for the clearing agent.',
        'Tracking is a manual status field, not a live carrier/AWB integration.',
        'Recipients are role-based (CTO, MD, Sales Manager, department manager), not named people.',
        'The MD approves the clearance payment through the Section 16 vendor-fee chain, not just receives a notification.',
        'Exports are rare enough that the four-step lighter flow is sufficient.',
    ];

    public function settings(): array
    {
        $saved = json_decode((string) Setting::get(self::SETTING_KEY, '{}'), true) ?: [];

        $labels = fn (array $defaults, $custom) => array_map(
            fn ($i) => trim((string) ($custom[$i] ?? '')) !== '' ? trim((string) $custom[$i]) : $defaults[$i],
            array_keys($defaults),
        );

        return [
            // 18.2: step 6 blocked until the permit is issued.
            'permit_gate' => (bool) ($saved['permit_gate'] ?? true),
            // 18.8.1: off = documents can follow, shipment is flagged Incomplete
            // and can't move past step 1 until they're in.
            'docs_required_at_creation' => (bool) ($saved['docs_required_at_creation'] ?? false),
            'import_labels' => $labels(self::IMPORT_LABELS, $saved['import_labels'] ?? []),
            'export_labels' => $labels(self::EXPORT_LABELS, $saved['export_labels'] ?? []),
            'confirmed_assumptions' => array_values(array_filter(
                (array) ($saved['confirmed_assumptions'] ?? []),
                fn ($i) => is_int($i) && isset(self::ASSUMPTIONS[$i]),
            )),
        ];
    }

    public function saveSettings(array $settings, ?int $userId): void
    {
        Setting::set(self::SETTING_KEY, json_encode($settings), $userId);
    }

    public function labels(Shipment $s): array
    {
        $cfg = $this->settings();

        return $s->direction === 'export' ? $cfg['export_labels'] : $cfg['import_labels'];
    }

    public function label(Shipment $s, ?int $step = null): string
    {
        return $this->labels($s)[($step ?? $s->step) - 1] ?? 'Step ' . ($step ?? $s->step);
    }

    public function lastStep(Shipment $s): int
    {
        return $s->direction === 'export' ? count(self::EXPORT_LABELS) : count(self::IMPORT_LABELS);
    }

    /** Documents the shipment must carry before it can move past step 1. */
    public static function requiredDocTypes(string $direction, string $freightMode): array
    {
        return array_values(array_filter([
            $freightMode === 'air' ? 'air_waybill' : 'bill_of_lading',
            'packing_list',
            'commercial_invoice',
            $direction === 'import' ? 'coa' : null,
        ]));
    }

    public static function allowedDocTypes(string $direction): array
    {
        return $direction === 'import'
            ? ['air_waybill', 'bill_of_lading', 'packing_list', 'commercial_invoice', 'coa', 'tmda_permit']
            : ['air_waybill', 'bill_of_lading', 'packing_list', 'commercial_invoice', 'recipient_receipt'];
    }

    public function missingDocs(Shipment $s): array
    {
        $have = $s->documents->pluck('type')->all();

        return array_values(array_diff(self::requiredDocTypes($s->direction, $s->freight_mode), $have));
    }

    /**
     * Plain-text reason the shipment cannot be at $target, or null. Checks
     * every gate at or below the target, so skipping ahead can't dodge one.
     */
    public function blockReason(Shipment $s, int $target): ?string
    {
        $cfg = $this->settings();
        $labels = $this->labels($s);
        $name = fn (int $step) => '"' . ($labels[$step - 1] ?? "step {$step}") . '"';

        if ($target >= 2 && ($missing = $this->missingDocs($s))) {
            $list = implode(', ', array_map(fn ($t) => self::DOC_LABELS[$t], $missing));

            return "Upload the missing documents before moving past {$name(1)}: {$list}.";
        }

        if ($s->direction === 'export') {
            if ($target >= 4 && ! $s->documents->contains('type', 'recipient_receipt')) {
                return "Upload the signed receipt from the recipient before marking it {$name(4)}.";
            }

            return null;
        }

        if ($target >= self::STEP_PERMIT_APPLIED && ! $s->tmda_application_ref) {
            return "Enter the TMDA permit application reference before {$name(self::STEP_PERMIT_APPLIED)}.";
        }
        if ($target === self::STEP_PERMIT_ISSUED && ! $s->tmda_issued_at) {
            return "Enter the date the TMDA permit was issued before {$name(self::STEP_PERMIT_ISSUED)}.";
        }
        if ($target >= self::STEP_TO_CLEARING_AGENT && $cfg['permit_gate'] && ! $s->tmda_issued_at) {
            return "Documents can't go to the clearing agent until the TMDA permit is issued (step "
                . self::STEP_PERMIT_ISSUED . '). This rule can be switched off in Shipments settings.';
        }
        if ($target >= self::STEP_ASSESSMENT && ! $s->control_number) {
            return "Enter the clearing agent's control number before {$name(self::STEP_ASSESSMENT)}.";
        }
        if ($target >= self::STEP_PAYMENT) {
            $fee = $s->vendorFee;
            if (! $fee) {
                return "Link the clearing agent's Section 16 vendor fee before {$name(self::STEP_PAYMENT)}.";
            }
            if ($fee->status !== 'paid') {
                // Section 16 owns the payment rule — report its own reason.
                $why = $fee->status === 'rejected' ? 'the vendor fee was rejected' : ($fee->paymentBlockReason() ?? 'it is awaiting the Director\'s approval');

                return "The clearing fee isn't paid yet: {$why}";
            }
        }

        return null;
    }

    /** none | action (documents incomplete) | blocked (next step gated) | done. */
    public function flag(Shipment $s): array
    {
        if ($s->step >= $this->lastStep($s)) {
            return ['flag' => 'done', 'reason' => null];
        }
        if ($this->missingDocs($s)) {
            return ['flag' => 'action', 'reason' => $this->blockReason($s, $s->step + 1)];
        }
        $reason = $this->blockReason($s, $s->step + 1);

        return ['flag' => $reason ? 'blocked' : 'none', 'reason' => $reason];
    }
}
