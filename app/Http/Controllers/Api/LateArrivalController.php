<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LateArrivalResource;
use App\Models\AppNotification;
use App\Models\LateArrival;
use App\Models\User;
use Illuminate\Http\Request;

class LateArrivalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = LateArrival::with('user');

        if ($user->hasHrAuthority()) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->user_id);
            }
        } else {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('date_from')) {
            $query->where('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('date', '<=', $request->date_to);
        }

        return LateArrivalResource::collection($query->latest('date')->latest('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'date'          => ['nullable', 'date'],
            'expected_time' => ['nullable', 'string', 'max:20'],
            'reason'        => ['nullable', 'string'],
        ]);

        $data['user_id'] = $request->user()->id;
        $data['date']    = $data['date'] ?? now()->toDateString();

        $late = LateArrival::create($data);

        $this->notifyHr($late);

        return response()->json(['data' => new LateArrivalResource($late->load('user'))], 201);
    }

    private function notifyHr(LateArrival $late): void
    {
        $name = $late->user?->name ?? 'A staff member';
        $when = $late->expected_time ? " — expected around {$late->expected_time}" : '';

        User::whereIn('role', User::HR_APPROVAL_ROLES)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                'type'        => 'late_arrival',
                'title'       => 'Running Late',
                'body'        => "{$name} will be late today{$when}." . ($late->reason ? " Reason: {$late->reason}" : ''),
                'entity_type' => 'late_arrival',
                'entity_id'   => $late->id,
                'is_read'     => false,
            ]));
    }
}
