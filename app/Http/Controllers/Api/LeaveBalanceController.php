<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveBalanceResource;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Http\Request;

class LeaveBalanceController extends Controller
{
    // Returns every active leave type's balance for the given user/year,
    // creating a zero-used row on the fly for any type that has no
    // balance row yet (nothing to deduct from until a request is
    // approved) so the caller always sees a complete picture.
    public function index(Request $request)
    {
        $user = $request->user();
        $targetUserId = $request->filled('user_id') && $user->hasHrAuthority()
            ? $request->integer('user_id')
            : $user->id;
        $year = $request->integer('year', now()->year);

        $types = LeaveType::where('active', true)->where('deducts_balance', true)->get();

        $existing = LeaveBalance::with('leaveType')
            ->where('user_id', $targetUserId)
            ->where('year', $year)
            ->get()
            ->keyBy('leave_type_id');

        $result = $types->map(function (LeaveType $type) use ($existing, $targetUserId, $year) {
            $balance = $existing->get($type->id) ?? new LeaveBalance([
                'user_id' => $targetUserId,
                'leave_type_id' => $type->id,
                'year' => $year,
                'allocated_days' => $type->default_days_per_year,
                'used_days' => 0,
            ]);
            $balance->setRelation('leaveType', $type);

            return $balance;
        });

        return LeaveBalanceResource::collection($result);
    }
}
