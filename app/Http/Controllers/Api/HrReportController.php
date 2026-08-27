<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Application;
use App\Models\Contract;
use App\Models\DisciplinaryCase;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PositionChange;
use App\Models\User;
use App\Models\Vacancy;
use Illuminate\Http\Request;

// All HR report endpoints are gated the same way as the rest of this HR
// module — hasStaffManageAuthority() (hr/admin). A finer HR-clerk-vs-
// HR-manager split (e.g. hiding payroll reports from a clerk) is a real
// future need once there's more than one HR role tier — not built here,
// since introducing a new role is its own decision, not implied by what
// exists today.
class HrReportController extends Controller
{
    private function authorize(Request $request): void
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to view HR reports.');
    }

    // Flat list (id, name, position, manager) rather than a nested tree —
    // Flutter renders it grouped by manager. Distinct from the free-form
    // department-sketch OrgChartEditor (org_chart_json setting), which
    // isn't tied to real staff/position data at all.
    public function orgChart(Request $request)
    {
        $this->authorize($request);

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
        $this->authorize($request);

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

    // Headcount by department (via position), position, gender — zone
    // stands in for "location" since there's no dedicated location field.
    public function headcountBreakdown(Request $request)
    {
        $this->authorize($request);

        $staff = User::with('position')->where('is_active', true)->get();

        $byDept = $staff->groupBy(fn (User $u) => $u->position?->department ?? 'Unassigned')->map->count();
        $byPosition = $staff->groupBy(fn (User $u) => $u->position?->title ?? 'Unassigned')->map->count();
        $byGender = $staff->groupBy(fn (User $u) => $u->gender ?? 'unspecified')->map->count();
        $byZone = $staff->groupBy(fn (User $u) => $u->zone ?? 'Unassigned')->map->count();

        return response()->json(['data' => [
            'total'        => $staff->count(),
            'by_department'=> $byDept,
            'by_position'  => $byPosition,
            'by_gender'    => $byGender,
            'by_location'  => $byZone,
        ]]);
    }

    // Compliance-export shape — includes statutory IDs the org-chart
    // snapshot deliberately leaves out.
    public function staffDirectory(Request $request)
    {
        $this->authorize($request);

        $staff = User::with('position')->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $staff->map(fn (User $u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'role' => $u->role, 'position_title' => $u->position?->title, 'gender' => $u->gender,
            'hire_date' => $u->hire_date?->toDateString(), 'zone' => $u->zone,
            'nssf_number' => $u->nssf_number, 'tin_number' => $u->tin_number, 'nida_number' => $u->nida_number,
        ])]);
    }

    // Every active staff/type balance for the year — the "who's sitting on
    // unused annual leave" report, plus a derived utilization percentage.
    public function leaveBalances(Request $request)
    {
        $this->authorize($request);

        $year = $request->integer('year', now()->year);
        $types = LeaveType::where('active', true)->where('deducts_balance', true)->get();
        $staff = User::where('is_active', true)->orderBy('name')->get();

        $existing = LeaveBalance::with(['leaveType', 'user'])->where('year', $year)->get()
            ->groupBy(fn ($b) => $b->user_id . ':' . $b->leave_type_id);

        $rows = [];
        foreach ($staff as $user) {
            foreach ($types as $type) {
                $balance = $existing->get("{$user->id}:{$type->id}")?->first();
                $allocated = $balance->allocated_days ?? $type->default_days_per_year;
                $used = $balance->used_days ?? 0;
                $rows[] = [
                    'user_id' => $user->id, 'user_name' => $user->name,
                    'leave_type_key' => $type->key, 'leave_type_label' => $type->label,
                    'allocated_days' => $allocated, 'used_days' => $used,
                    'remaining_days' => max(0, $allocated - $used),
                    'utilization_pct' => $allocated > 0 ? round(($used / $allocated) * 100, 1) : 0.0,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    // Approved leave overlapping the given range (default: current month) —
    // "who's out this month."
    public function leaveCalendar(Request $request)
    {
        $this->authorize($request);

        $start = $request->filled('start') ? $request->date('start') : now()->startOfMonth();
        $end   = $request->filled('end') ? $request->date('end') : now()->endOfMonth();

        $requests = LeaveRequest::with(['user', 'leaveType'])
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->orderBy('start_date')
            ->get();

        return response()->json(['data' => $requests->map(fn (LeaveRequest $r) => [
            'user_name' => $r->user?->name, 'leave_type_label' => $r->leaveType?->label ?? $r->type,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
        ])]);
    }

    // Vacancy pipeline (applicants per stage), talent pool size, and which
    // source channels actually convert to hires.
    public function recruitmentSummary(Request $request)
    {
        $this->authorize($request);

        $vacancies = Vacancy::with('position')->withCount('applications')
            ->where('status', 'open')->get();

        $pipeline = $vacancies->map(function (Vacancy $v) {
            // Postgres/PDO returns raw COUNT(*) as a numeric *string*, not
            // a native int — json_encode then emits a JSON string ("3"),
            // which fails Flutter's `(v as num)` cast in VacancyPipelineEntry.
            // Cast explicitly rather than relying on PDO/driver behavior.
            $stages = $v->applications()->selectRaw('status, count(*) as c')->groupBy('status')
                ->pluck('c', 'status')->map(fn ($c) => (int) $c);
            $daysOpen = $v->opened_at->diffInDays(now());
            return [
                'vacancy_id' => $v->id, 'position_title' => $v->position?->title,
                'days_open' => $daysOpen, 'total_applications' => $v->applications_count,
                'by_stage' => $stages,
            ];
        });

        $talentPoolCount = Applicant::where('talent_pool', true)->count();

        $hiredBySource = Application::with('applicant')->where('status', 'hired')->get()
            ->groupBy(fn (Application $a) => $a->applicant?->source_channel ?? 'Unknown')
            ->map->count();

        return response()->json(['data' => [
            'open_vacancies'   => $vacancies->count(),
            'pipeline'         => $pipeline,
            'talent_pool_count'=> $talentPoolCount,
            'hires_by_source'  => $hiredBySource,
        ]]);
    }

    // Active contracts with an end date, soonest first — "needs renewal
    // action before it lapses."
    public function contractsExpiring(Request $request)
    {
        $this->authorize($request);

        $withinDays = $request->integer('within_days', 90);

        $contracts = Contract::with('user')
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<=', now()->addDays($withinDays))
            ->orderBy('end_date')
            ->get();

        return response()->json(['data' => $contracts->map(fn (Contract $c) => [
            'user_name' => $c->user?->name, 'contract_type' => $c->contract_type,
            'end_date' => $c->end_date->toDateString(),
            'days_remaining' => now()->diffInDays($c->end_date, false),
        ])]);
    }

    // Active cases grouped by stage, plus repeat-offender detection (staff
    // with more than one case, active or historical).
    public function disciplinarySummary(Request $request)
    {
        $this->authorize($request);

        $active = DisciplinaryCase::where('status', 'open')->get();
        $byStage = $active->groupBy('stage')->map->count();

        $repeatOffenders = DisciplinaryCase::with('user')
            ->selectRaw('user_id, count(*) as case_count')
            ->groupBy('user_id')
            ->havingRaw('count(*) > 1')
            ->get()
            // Same Postgres/PDO string-vs-int issue as recruitmentSummary()'s
            // by_stage counts — cast explicitly, don't trust the raw value's type.
            ->map(fn ($row) => ['user_name' => $row->user?->name, 'case_count' => (int) $row->case_count]);

        return response()->json(['data' => [
            'active_count' => $active->count(),
            'by_stage'     => $byStage,
            'repeat_offenders' => $repeatOffenders,
        ]]);
    }

    // Promotion/demotion log across all staff, most recent first.
    public function careerProgressions(Request $request)
    {
        $this->authorize($request);

        $changes = PositionChange::with(['user', 'fromPosition', 'toPosition', 'approvedBy'])
            ->latest('effective_date')->limit(50)->get();

        return response()->json(['data' => $changes->map(fn (PositionChange $c) => [
            'user_name' => $c->user?->name, 'from_position' => $c->fromPosition?->title,
            'to_position' => $c->toPosition?->title, 'change_type' => $c->change_type,
            'effective_date' => $c->effective_date->toDateString(),
        ])]);
    }
}
