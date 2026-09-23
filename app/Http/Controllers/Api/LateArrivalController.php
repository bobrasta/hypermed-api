<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LateArrivalResource;
use App\Models\AppNotification;
use App\Models\LateArrival;
use App\Models\User;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;

class LateArrivalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $query = LateArrival::with('user');

        // Same self-scoping guarantee as LeaveController::index() — mine=1
        // forces self-scoping even for hr/admin callers, regardless of
        // whether user_id was also passed.
        if ($user->hasHrAuthority() && ! $request->boolean('mine')) {
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
        $reasonSuffix = $late->reason ? " Reason: {$late->reason}" : '';

        User::whereIn('role', User::HR_APPROVAL_ROLES)
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id'     => $id,
                ...app(NotificationTemplateService::class)->render('late_arrival.notify_hr', [
                    'name'          => $name,
                    'when_suffix'   => $when,
                    'reason_suffix' => $reasonSuffix,
                ]),
                'entity_type' => 'late_arrival',
                'entity_id'   => $late->id,
                'is_read'     => false,
            ]));
    }
}
