<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Section 12 of hypermed_claude_code_prompt.md: connects existing cost
 * sources instead of building a parallel cost table.
 * - parts: parts_used.qty * parts_used.unit_cost, joined via tickets on
 *   this machine (unit_cost is already captured at time of use).
 * - labor + travel (per-diem + transport): per_diem_lines already carries
 *   labor_cost alongside per_diem_cost/transport_fare, summed per request,
 *   for requests linked to this machine's tickets via service_ticket_id.
 *   Only 'paid' requests count — a pending/rejected request isn't an
 *   actual incurred cost yet.
 * - other/external: nothing in the system currently records vendor costs
 *   per ticket, so it's always 0 — not fabricated. The "Based on recorded
 *   costs only" note on the tab (spec's own wording) is why.
 */
class MachineCostService
{
    public function breakdown(Machine $machine): array
    {
        $ticketIds = $machine->tickets()->pluck('id');

        $partsRows = DB::table('parts_used')
            ->whereIn('ticket_id', $ticketIds)
            ->select('ticket_id', DB::raw('SUM(qty * unit_cost) as cost'))
            ->groupBy('ticket_id')
            ->pluck('cost', 'ticket_id');

        $travelRows = DB::table('per_diem_lines')
            ->join('per_diem_requests', 'per_diem_requests.id', '=', 'per_diem_lines.per_diem_request_id')
            ->where('per_diem_requests.status', 'paid')
            ->whereIn('per_diem_requests.service_ticket_id', $ticketIds)
            ->select(
                'per_diem_requests.service_ticket_id as ticket_id',
                DB::raw('SUM(per_diem_lines.labor_cost) as labor'),
                DB::raw('SUM(per_diem_lines.per_diem_cost + per_diem_lines.transport_fare) as travel'),
            )
            ->groupBy('per_diem_requests.service_ticket_id')
            ->get()
            ->keyBy('ticket_id');

        $tickets = $machine->tickets()->select('id', 'ticket_number', 'type', 'assigned_to', 'created_at')
            ->with('assignee:id,name')
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $totalParts = 0;
        $totalLabor = 0;
        $totalTravel = 0;
        foreach ($tickets as $ticket) {
            $parts = (int) ($partsRows[$ticket->id] ?? 0);
            $travelRow = $travelRows[$ticket->id] ?? null;
            $labor = (int) ($travelRow->labor ?? 0);
            $travel = (int) ($travelRow->travel ?? 0);
            if ($parts === 0 && $labor === 0 && $travel === 0) {
                continue; // Only tickets that actually cost something show as a row.
            }
            $totalParts += $parts;
            $totalLabor += $labor;
            $totalTravel += $travel;
            $rows[] = [
                'ticket_id'      => $ticket->id,
                'ticket_number'  => $ticket->ticket_number,
                'ticket_type'    => $ticket->type,
                'technician'     => $ticket->assignee?->name,
                'date'           => $ticket->created_at?->toDateString(),
                'parts'          => $parts,
                'labor'          => $labor,
                'travel'         => $travel,
                'other'          => 0,
                'total'          => $parts + $labor + $travel,
            ];
        }

        $grandTotal = $totalParts + $totalLabor + $totalTravel;
        $twelveMonthsAgo = now()->subMonths(12)->toDateString();
        $last12Months = collect($rows)->filter(fn ($r) => $r['date'] && $r['date'] >= $twelveMonthsAgo)->sum('total');

        return [
            'rows'                      => $rows,
            'total_parts'               => $totalParts,
            'total_labor'               => $totalLabor,
            'total_travel'              => $totalTravel,
            'total_other'               => 0,
            'grand_total'               => $grandTotal,
            'last_12_months_total'      => (int) $last12Months,
            'service_count'             => count($rows),
            'average_cost_per_service'  => count($rows) > 0 ? (int) round($grandTotal / count($rows)) : 0,
            'viability'                 => $this->viability($machine, $grandTotal),
        ];
    }

    // Server-side so web/Flutter/reports always agree, per spec.
    public function viability(Machine $machine, int $totalServiceCost): array
    {
        $thresholdPercent = (float) Setting::get('machine_replacement_threshold_percent', '0.5');

        if ($machine->purchase_cost_tsh === null) {
            return [
                'has_purchase_cost'        => false,
                'threshold_percent'        => $thresholdPercent,
                'percent_of_purchase_cost' => null,
                'exceeds_threshold'        => false,
            ];
        }

        $percent = $machine->purchase_cost_tsh > 0
            ? $totalServiceCost / $machine->purchase_cost_tsh
            : null;

        return [
            'has_purchase_cost'         => true,
            'purchase_cost'             => $machine->purchase_cost,
            'purchase_cost_currency'    => $machine->purchase_cost_currency,
            'purchase_cost_tsh'         => $machine->purchase_cost_tsh,
            'purchase_cost_recorded_at' => $machine->purchase_cost_recorded_at?->toIso8601String(),
            'total_service_cost'        => $totalServiceCost,
            'threshold_percent'         => $thresholdPercent,
            'percent_of_purchase_cost'  => $percent,
            'exceeds_threshold'         => $percent !== null && $percent > $thresholdPercent,
        ];
    }

    // Lightweight version for list-level badges — avoids building the full
    // per-ticket breakdown just to answer "does this one machine exceed the
    // threshold". Still server-side, still agrees with breakdown()'s number.
    public function exceedsThreshold(Machine $machine): bool
    {
        if ($machine->purchase_cost_tsh === null || $machine->purchase_cost_tsh <= 0) {
            return false;
        }

        $ticketIds = $machine->tickets()->pluck('id');
        $parts = (int) DB::table('parts_used')->whereIn('ticket_id', $ticketIds)
            ->selectRaw('COALESCE(SUM(qty * unit_cost), 0) as t')->value('t');
        $laborAndTravel = (int) DB::table('per_diem_lines')
            ->join('per_diem_requests', 'per_diem_requests.id', '=', 'per_diem_lines.per_diem_request_id')
            ->where('per_diem_requests.status', 'paid')
            ->whereIn('per_diem_requests.service_ticket_id', $ticketIds)
            ->selectRaw('COALESCE(SUM(labor_cost + per_diem_cost + transport_fare), 0) as t')->value('t');

        $thresholdPercent = (float) Setting::get('machine_replacement_threshold_percent', '0.5');

        return ($parts + $laborAndTravel) > $thresholdPercent * $machine->purchase_cost_tsh;
    }
}
