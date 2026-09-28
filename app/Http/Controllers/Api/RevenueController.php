<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RevenueController extends Controller
{
    public function summary(Request $request)
    {
        abort_if(! $request->user()->hasRevenueViewAuthority(), 403, 'You are not authorised to view revenue.');

        // ?year=YYYY gives Jan-Dec of that year (the apps' "this year" default);
        // without it, the rolling last 12 months as before.
        $year = $request->filled('year') ? max(2000, min($request->integer('year'), 2100)) : null;

        return response()->json(['data' => $this->buildRevenueSummary($year)]);
    }

    public function buildRevenueSummary(?int $year = null)
    {
        // Cache key includes the current month so it auto-invalidates on the 1st
        $cacheKey = 'revenue:summary:' . now()->format('Y-m') . ($year ? ":y$year" : '');
        $first = $year ? Carbon::create($year, 1, 1) : Carbon::now()->subMonths(11)->startOfMonth();

        $months = Cache::remember($cacheKey, 600, function () use ($first) {
            $start = $first->toDateString();
            $end = $first->copy()->addMonths(11)->endOfMonth()->toDateString();

            // Single GROUP BY query instead of 12 individual month queries
            $rows = Invoice::whereIn('status', ['paid', 'partial'])
                ->whereBetween('issue_date', [$start, $end])
                ->selectRaw("TO_CHAR(issue_date, 'YYYY-MM') AS month_key, SUM(amount_paid) AS total")
                ->groupByRaw("TO_CHAR(issue_date, 'YYYY-MM')")
                ->pluck('total', 'month_key');

            return collect(range(0, 11))->map(function ($i) use ($rows, $first) {
                $month = $first->copy()->addMonths($i);
                $key   = $month->format('Y-m');

                return [
                    'month'  => $key,
                    'label'  => $month->format('M Y'),
                    'actual' => (int) ($rows[$key] ?? 0),
                ];
            })->values();
        });

        // Read outside the actuals cache so an admin-updated target shows up
        // immediately instead of waiting on the 10-minute cache to expire.
        $target = (int) Setting::get('revenue_monthly_target', 0);

        return $months->map(fn ($m) => [...$m, 'target' => $target]);
    }

    public function byHospital(Request $request)
    {
        abort_if(! $request->user()->hasRevenueViewAuthority(), 403, 'You are not authorised to view revenue.');

        // With a period: top 10 clients by invoiced total in that range
        // (field name kept so existing clients keep working).
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $from = $request->filled('date_from') ? $request->date('date_from')->toDateString() : '1900-01-01';
            $to = $request->filled('date_to') ? $request->date('date_to')->toDateString() : '2999-12-31';

            $rows = Invoice::query()
                ->join('hospitals', 'hospitals.id', '=', 'invoices.hospital_id')
                ->whereBetween('invoices.issue_date', [$from, $to])
                ->where('invoices.status', '!=', 'cancelled')
                ->groupBy('hospitals.id', 'hospitals.name', 'hospitals.short_code')
                ->orderByRaw('SUM(invoices.total) DESC')
                ->limit(10)
                ->get(['hospitals.id', 'hospitals.name', 'hospitals.short_code', \DB::raw('SUM(invoices.total) AS revenue')])
                ->map(fn ($h) => [
                    'id'              => $h->id,
                    'name'            => $h->name,
                    'short_code'      => $h->short_code,
                    'revenue_monthly' => (int) $h->revenue,
                ]);

            return response()->json(['data' => $rows]);
        }

        $hospitals = Cache::remember('revenue:by-hospital', 600, function () {
            return Hospital::select('id', 'name', 'short_code', 'revenue_monthly')
                ->orderByDesc('revenue_monthly')
                ->limit(10)
                ->get()
                ->map(fn ($h) => [
                    'id'              => $h->id,
                    'name'            => $h->name,
                    'short_code'      => $h->short_code,
                    'revenue_monthly' => $h->revenue_monthly,
                ]);
        });

        return response()->json(['data' => $hospitals]);
    }
}
