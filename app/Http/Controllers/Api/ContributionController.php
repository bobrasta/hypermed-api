<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Machine;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Staff contributions (2026-10-23): a per-person record of who did what in a
 * period, for the Director to gauge bonuses. No formula, no amounts — only
 * counts, each backed by the records behind it (see show()).
 *
 * Same crediting as My Performance (PerformanceController::mine):
 * - quotations prepared: created_by (stays with the preparer even when Sales
 *   sends or converts it), created in the period;
 * - of those, matured to an invoice: quotation → sales order → a final,
 *   non-cancelled invoice (any date);
 * - installations: machines installed_by, installed_at in the period;
 * - services: repair tickets assigned_to, resolved in the period;
 * - deals won: leads assigned_to now at "won", last updated in the period
 *   (leads keep no won date of their own).
 */
class ContributionController extends Controller
{
    public const METRICS = ['quotations', 'quotations_invoiced', 'installations', 'services', 'deals_won'];

    public function index(Request $request)
    {
        $this->authorizeView($request);
        [$from, $to] = $this->period($request);

        $count = fn (Builder $q, string $column) => $q->selectRaw("{$column} AS uid, COUNT(*) AS n")->groupBy($column)->pluck('n', 'uid');
        $metrics = [
            'quotations' => $count($this->quotations($from, $to), 'created_by'),
            'quotations_invoiced' => $count($this->quotations($from, $to)->whereExists($this->matured()), 'created_by'),
            'installations' => $count($this->installations($from, $to), 'installed_by'),
            'services' => $count($this->services($from, $to), 'assigned_to'),
            'deals_won' => $count($this->dealsWon($from, $to), 'assigned_to'),
        ];
        $invoicedValue = $this->quotations($from, $to)->whereExists($this->matured())
            ->selectRaw('created_by AS uid, SUM(total_amount) AS v')->groupBy('created_by')->pluck('v', 'uid');

        $ids = collect($metrics)->flatMap(fn ($m) => $m->keys())->unique();
        $rows = User::whereIn('id', $ids)->orWhere('is_active', true)->orderBy('name')->get(['id', 'name', 'role', 'is_active'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role, 'is_active' => (bool) $u->is_active]
                + collect($metrics)->map(fn ($m) => (int) ($m[$u->id] ?? 0))->all()
                + ['invoiced_value' => (int) ($invoicedValue[$u->id] ?? 0)])
            // Only people with something on record, so the list is the trail, not the staff directory.
            ->filter(fn ($r) => collect(self::METRICS)->some(fn ($k) => $r[$k] > 0))
            ->values();

        return response()->json(['data' => [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'people' => $rows,
        ]]);
    }

    /** The records behind one person's number. */
    public function show(Request $request, User $user, string $metric)
    {
        $this->authorizeView($request);
        abort_unless(in_array($metric, self::METRICS, true), 404);
        [$from, $to] = $this->period($request);

        $items = match ($metric) {
            'quotations', 'quotations_invoiced' => $this->quotations($from, $to)->where('created_by', $user->id)
                ->when($metric === 'quotations_invoiced', fn ($q) => $q->whereExists($this->matured()))
                ->latest('created_at')->get()
                ->map(fn (Quotation $q) => [
                    'id' => $q->id, 'reference' => $q->quotation_number, 'title' => $q->client_name,
                    'date' => $q->created_at?->toDateString(), 'value' => (int) $q->total_amount, 'status' => $q->status,
                    'invoice' => DB::table('sales_orders')->join('invoices', 'invoices.sales_order_id', '=', 'sales_orders.id')
                        ->where('sales_orders.quotation_id', $q->id)->whereNotIn('invoices.status', ['draft', 'proforma', 'cancelled'])
                        ->value('invoices.invoice_number'),
                ]),
            'installations' => $this->installations($from, $to)->where('installed_by', $user->id)->with('hospital:id,name')
                ->latest('installed_at')->get()
                ->map(fn (Machine $m) => [
                    'id' => $m->id, 'reference' => $m->serial_no, 'title' => trim("{$m->model} · " . ($m->hospital?->name ?? '—'), ' ·'),
                    'date' => $m->installed_at?->toDateString(),
                ]),
            'services' => $this->services($from, $to)->where('assigned_to', $user->id)->with('hospital:id,name')
                ->latest('resolved_at')->get()
                ->map(fn (ServiceTicket $t) => [
                    'id' => $t->id, 'reference' => $t->ticket_number, 'title' => $t->hospital?->name ?? '—',
                    'date' => Carbon::parse($t->resolved_at)->toDateString(),
                ]),
            'deals_won' => $this->dealsWon($from, $to)->where('assigned_to', $user->id)->with('hospital:id,name')->latest('updated_at')->get()
                ->map(fn (SalesLead $l) => [
                    'id' => $l->id, 'reference' => 'LEAD-' . str_pad((string) $l->id, 4, '0', STR_PAD_LEFT),
                    'title' => $l->hospital?->name ?? $l->hospital_name_raw ?? '—',
                    'date' => $l->updated_at?->toDateString(), 'value' => (int) $l->deal_value,
                ]),
        };

        return response()->json(['data' => [
            'person' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role],
            'metric' => $metric, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'items' => $items->values(),
        ]]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(app(EffectivePermissionResolver::class)->can($request->user(), 'screens.contributions'), 403,
            'Only the Director can view staff contributions.');
    }

    /** date_from/date_to like every dated list; this year when not given. */
    private function period(Request $request): array
    {
        return [
            $request->filled('date_from') ? $request->date('date_from')->startOfDay() : now()->startOfYear(),
            $request->filled('date_to') ? $request->date('date_to')->endOfDay() : now()->endOfYear(),
        ];
    }

    private function quotations(Carbon $from, Carbon $to): Builder
    {
        return Quotation::query()->whereBetween('quotations.created_at', [$from, $to]);
    }

    /** A sales order raised from the quotation has a final, non-cancelled invoice. */
    private function matured(): \Closure
    {
        return fn ($q) => $q->select(DB::raw(1))->from('sales_orders')
            ->join('invoices', 'invoices.sales_order_id', '=', 'sales_orders.id')
            ->whereColumn('sales_orders.quotation_id', 'quotations.id')
            ->whereNotIn('invoices.status', ['draft', 'proforma', 'cancelled']);
    }

    private function installations(Carbon $from, Carbon $to): Builder
    {
        return Machine::query()->whereNotNull('installed_by')->whereBetween('installed_at', [$from, $to]);
    }

    private function services(Carbon $from, Carbon $to): Builder
    {
        return ServiceTicket::query()->where('type', '!=', 'installation')->whereNotNull('assigned_to')
            ->whereNotNull('resolved_at')->whereBetween('resolved_at', [$from, $to]);
    }

    private function dealsWon(Carbon $from, Carbon $to): Builder
    {
        return SalesLead::query()->where('stage', 'won')->whereNotNull('assigned_to')->whereBetween('updated_at', [$from, $to]);
    }
}
