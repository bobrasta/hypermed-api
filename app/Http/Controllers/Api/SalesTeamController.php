<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Quotation;
use App\Models\SalesLead;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

// Backs the richer "My Team" screen (both frontends) — sales.create_subordinate_user
// gated, same as StaffController's scoped path. Deliberately its own
// controller rather than folding into StaffController/UserResource: these
// are sales-specific computed fields (open leads, revenue), not general
// staff attributes every caller of /staff should see.
class SalesTeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_if(! $user->hasSalesCreateSubordinateAuthority(), 403,
            'Access Denied: you do not have permission to view team management.');

        $team = User::where('manager_id', $user->id)->where('is_active', true)->get();
        $teamIds = $team->pluck('id');

        $startOfMonth = Carbon::now()->startOfMonth();

        // Revenue MTD per rep — same shape as DashboardController's
        // revenueThisMonth, just grouped by the order's creator instead of
        // summed for one owner. Filters on the join directly (rather than a
        // separate whereHas) — both invoices and sales_orders have a
        // 'status' column, so every filtered column needs its table
        // qualified once joined, or Postgres rejects the query outright as
        // ambiguous rather than silently guessing.
        $revenueByRep = \App\Models\Invoice::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'invoices.sales_order_id')
            ->whereIn('sales_orders.created_by', $teamIds)
            ->where('invoices.issue_date', '>=', $startOfMonth->toDateString())
            ->whereIn('invoices.status', ['paid', 'partial'])
            ->selectRaw('sales_orders.created_by AS rep_id, COALESCE(SUM(invoices.amount_paid), 0) AS total')
            ->groupBy('sales_orders.created_by')
            ->pluck('total', 'rep_id');

        $openLeadsByRep = SalesLead::whereIn('assigned_to', $teamIds)
            ->whereNotIn('stage', ['won', 'lost'])
            ->selectRaw('assigned_to AS rep_id, COUNT(*) AS count')
            ->groupBy('assigned_to')
            ->pluck('count', 'rep_id');

        $members = $team->map(fn (User $m) => [
            'id'                    => $m->id,
            'name'                  => $m->name,
            'email'                 => $m->email,
            'initials'              => collect(explode(' ', trim($m->name)))->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
            'zone'                  => $m->zone,
            'avail_status'          => $m->avail_status,
            'max_discount_percent'  => $m->max_discount_percent,
            'open_leads'            => (int) ($openLeadsByRep[$m->id] ?? 0),
            'revenue_mtd'           => (int) ($revenueByRep[$m->id] ?? 0),
        ])->values();

        return response()->json([
            'data' => [
                'team'            => $members,
                'recent_activity' => $this->recentActivity($teamIds),
            ],
        ]);
    }

    // Unmanaged sales reps — real accounts that exist (e.g. created directly
    // via Staff, or a manager change left them orphaned) with no manager_id
    // set at all. Lets a sales_manager claim one as their own report,
    // closing that gap, rather than only ever creating brand-new accounts.
    public function unassigned(Request $request)
    {
        abort_if(! $request->user()->hasSalesCreateSubordinateAuthority(), 403,
            'Access Denied: you do not have permission to view team management.');

        $reps = User::where('role', 'sales')->whereNull('manager_id')->where('is_active', true)->get();

        return response()->json(['data' => $reps->map(fn (User $m) => [
            'id' => $m->id, 'name' => $m->name, 'email' => $m->email,
        ])->values()]);
    }

    // Claim an existing unmanaged rep. Deliberately narrow — only role
    // 'sales' AND currently manager_id === null, so a sales_manager can
    // never poach a rep already reporting to someone else.
    public function assign(Request $request, User $user)
    {
        $manager = $request->user();
        abort_if(! $manager->hasSalesCreateSubordinateAuthority(), 403,
            'Access Denied: you do not have permission to manage your team.');
        abort_if($user->role !== 'sales', 422, 'Only sales reps can be assigned to your team.');
        abort_if($user->manager_id !== null, 422, 'This rep already reports to someone else.');

        $user->update(['manager_id' => $manager->id]);

        return response()->json(['data' => ['id' => $user->id, 'manager_id' => $user->manager_id]]);
    }

    // A best-effort recent feed from real, existing timestamps — quotations
    // issued and orders confirmed by the team, most recent first. Not a
    // full audit log (lead stage changes aren't tracked with history
    // anywhere in this app today), so this deliberately only surfaces
    // events that have a real timestamp to sort and describe accurately.
    private function recentActivity($teamIds): array
    {
        $quotes = Quotation::whereIn('created_by', $teamIds)
            ->whereNotNull('sent_at')
            ->with('createdBy')
            ->latest('sent_at')
            ->limit(5)
            ->get()
            ->map(fn ($q) => [
                'title' => "{$q->createdBy?->name} issued {$q->quotation_number}",
                'note'  => "{$q->client_name} · TSh " . number_format($q->total_amount),
                'at'    => $q->sent_at?->toIso8601String(),
            ]);

        $orders = SalesOrder::whereIn('created_by', $teamIds)
            ->whereNotNull('confirmed_at')
            ->with('createdBy')
            ->latest('confirmed_at')
            ->limit(5)
            ->get()
            ->map(fn ($o) => [
                'title' => "{$o->createdBy?->name} confirmed {$o->order_number}",
                'note'  => "{$o->client_name} · TSh " . number_format($o->total_amount),
                'at'    => $o->confirmed_at?->toIso8601String(),
            ]);

        return $quotes->concat($orders)
            ->sortByDesc('at')
            ->values()
            ->take(8)
            ->all();
    }
}
