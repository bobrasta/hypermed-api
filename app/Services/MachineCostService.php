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
 *
 * Section 6, generalized to every ticket type: a ticket's machine list
 * comes from Machine::allTicketIds() (legacy machine_id column + the
 * service_ticket_machines pivot combined), and a ticket's cost is split
 * equally across however many active machines it covers ("unless
 * per-machine amounts exist" per spec — no such mechanism exists yet, so
 * it's always an equal split for a multi-machine ticket).
 */
class MachineCostService
{
    public function breakdown(Machine $machine): array
    {
        $ticketIds = $machine->allTicketIds();

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

        $machineCounts = $this->activeMachineCounts($ticketIds);

        $tickets = $machine->allTickets()->select('id', 'ticket_number', 'type', 'assigned_to', 'created_at')
            ->with('assignee:id,name')
            ->orderByDesc('created_at')
            ->get();

        $rows = [];
        $totalParts = 0;
        $totalLabor = 0;
        $totalTravel = 0;
        foreach ($tickets as $ticket) {
            $splitBy = $machineCounts[$ticket->id] ?? 1;
            $parts = (int) round(($partsRows[$ticket->id] ?? 0) / $splitBy);
            $travelRow = $travelRows[$ticket->id] ?? null;
            $labor = (int) round(($travelRow->labor ?? 0) / $splitBy);
            $travel = (int) round(($travelRow->travel ?? 0) / $splitBy);
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
                // Only meaningful context when it isn't 1 — a single-machine
                // ticket's cost isn't "split", it's just the whole thing.
                'split_across_machines' => $splitBy > 1 ? $splitBy : null,
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
    // threshold". Still server-side, still agrees with breakdown()'s number
    // (same per-ticket split-then-sum, just without the row detail).
    public function exceedsThreshold(Machine $machine): bool
    {
        if ($machine->purchase_cost_tsh === null || $machine->purchase_cost_tsh <= 0) {
            return false;
        }

        $ticketIds = $machine->allTicketIds();
        $machineCounts = $this->activeMachineCounts($ticketIds);

        $partsByTicket = DB::table('parts_used')->whereIn('ticket_id', $ticketIds)
            ->select('ticket_id', DB::raw('SUM(qty * unit_cost) as cost'))
            ->groupBy('ticket_id')->pluck('cost', 'ticket_id');
        $travelByTicket = DB::table('per_diem_lines')
            ->join('per_diem_requests', 'per_diem_requests.id', '=', 'per_diem_lines.per_diem_request_id')
            ->where('per_diem_requests.status', 'paid')
            ->whereIn('per_diem_requests.service_ticket_id', $ticketIds)
            ->select('per_diem_requests.service_ticket_id as ticket_id', DB::raw('SUM(labor_cost + per_diem_cost + transport_fare) as cost'))
            ->groupBy('per_diem_requests.service_ticket_id')->pluck('cost', 'ticket_id');

        $total = 0;
        foreach ($ticketIds as $id) {
            $splitBy = $machineCounts[$id] ?? 1;
            $total += round((($partsByTicket[$id] ?? 0) + ($travelByTicket[$id] ?? 0)) / $splitBy);
        }

        $thresholdPercent = (float) Setting::get('machine_replacement_threshold_percent', '0.5');

        return $total > $thresholdPercent * $machine->purchase_cost_tsh;
    }

    // service_ticket_id => count of active (not soft-removed) machines on
    // it. A ticket with no pivot rows at all (shouldn't happen post-Section-
    // 6 backfill, but defensive) falls back to 1 wherever this is consulted.
    private function activeMachineCounts($ticketIds)
    {
        return DB::table('service_ticket_machines')
            ->whereIn('service_ticket_id', $ticketIds)
            ->whereNull('removed_at')
            ->select('service_ticket_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('service_ticket_id')
            ->pluck('cnt', 'service_ticket_id');
    }
}
