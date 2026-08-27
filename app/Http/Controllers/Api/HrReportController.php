<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Http\Request;

class HrReportController extends Controller
{
    // Flat list (id, name, position, manager) rather than a nested tree —
    // Flutter renders it grouped by manager. Distinct from the free-form
    // department-sketch OrgChartEditor (org_chart_json setting), which
    // isn't tied to real staff/position data at all.
    public function orgChart()
    {
        $staff = User::with(['position', 'manager'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $staff->map(fn (User $u) => [
            'id'            => $u->id,
            'name'          => $u->name,
            'role'          => $u->role,
            'position_title' => $u->position?->title,
            'manager_id'    => $u->manager_id,
            'manager_name'  => $u->manager?->name,
        ])]);
    }

    public function turnover(Request $request)
    {
        $year = $request->integer('year', now()->year);

        $departed = Contract::whereIn('status', ['ended', 'resigned'])
            ->where(function ($q) use ($year) {
                $q->whereYear('end_date', $year)->orWhereYear('resignation_date', $year);
            })
            ->count();

        $headcount = User::where('is_active', true)->count();

        return response()->json(['data' => [
            'year'            => $year,
            'departed_count'  => $departed,
            'headcount'       => $headcount,
            'turnover_rate'   => $headcount > 0 ? round(($departed / $headcount) * 100, 1) : 0.0,
        ]]);
    }
}
