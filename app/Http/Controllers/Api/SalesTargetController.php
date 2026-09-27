<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesTarget;
use App\Models\User;
use Illuminate\Http\Request;

// Monthly revenue targets per rep. Setting them is sales.approve_order work
// (super_admin/admin/sales_manager) — the same tier that approves a rep's
// discounts. A rep can read their own targets and nobody else's.
class SalesTargetController extends Controller
{
    public const REP_ROLES = ['sales', 'sales_manager'];

    public function index(Request $request)
    {
        $user = $request->user();
        $year = (int) $request->query('year', now()->year);
        $canManage = $user->hasSalesApprovalAuthority();

        $reps = $canManage
            ? User::whereIn('role', self::REP_ROLES)->where('is_active', true)->orderBy('name')->get()
            : collect([$user]);

        $targets = SalesTarget::where('year', $year)
            ->whereIn('user_id', $reps->pluck('id'))
            ->get()
            ->groupBy('user_id');

        return response()->json(['data' => [
            'year'       => $year,
            'can_manage' => $canManage,
            'reps'       => $reps->map(fn (User $r) => [
                'id'     => $r->id,
                'name'   => $r->name,
                'role'   => $r->role,
                'months' => collect(range(1, 12))->map(fn ($m) => (int) (($targets[$r->id] ?? collect())->firstWhere('month', $m)?->amount ?? 0))->all(),
            ])->values(),
        ]]);
    }

    public function update(Request $request)
    {
        abort_if(! $request->user()->hasSalesApprovalAuthority(), 403,
            'Only a sales manager can set sales targets.');

        $data = $request->validate([
            'year'               => ['required', 'integer', 'min:2020', 'max:2100'],
            'targets'            => ['required', 'array'],
            'targets.*.user_id'  => ['required', 'exists:users,id'],
            'targets.*.month'    => ['required', 'integer', 'min:1', 'max:12'],
            'targets.*.amount'   => ['required', 'integer', 'min:0'],
        ]);

        foreach ($data['targets'] as $t) {
            SalesTarget::updateOrCreate(
                ['user_id' => $t['user_id'], 'year' => $data['year'], 'month' => $t['month']],
                ['amount' => $t['amount'], 'set_by' => $request->user()->id],
            );
        }

        return $this->index($request->merge(['year' => $data['year']]));
    }
}
