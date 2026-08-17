<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class RevenueController extends Controller
{
    public function summary()
    {
        // Cache key includes the current month so it auto-invalidates on the 1st
        $cacheKey = 'revenue:summary:' . now()->format('Y-m');

        $months = Cache::remember($cacheKey, 600, function () {
            $start = Carbon::now()->subMonths(11)->startOfMonth()->toDateString();

            // Single GROUP BY query instead of 12 individual month queries
            $rows = Invoice::whereIn('status', ['paid', 'partial'])
                ->where('issue_date', '>=', $start)
                ->selectRaw("TO_CHAR(issue_date, 'YYYY-MM') AS month_key, SUM(amount_paid) AS total")
                ->groupByRaw("TO_CHAR(issue_date, 'YYYY-MM')")
                ->pluck('total', 'month_key');

            return collect(range(11, 0))->map(function ($offset) use ($rows) {
                $month = Carbon::now()->subMonths($offset);
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

        return response()->json(['data' => $months->map(fn ($m) => [...$m, 'target' => $target])]);
    }

    public function byHospital()
    {
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
