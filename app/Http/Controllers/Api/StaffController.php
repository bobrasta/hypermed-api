<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    public function index()
    {
        $staff = User::with('currentTask')
            ->where('is_active', true)
            ->get();

        return UserResource::collection($staff);
    }

    public function store(Request $request)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to create staff accounts.');

        $data = $request->validate([
            'name'         => ['required', 'string'],
            'email'        => ['required', 'email', 'unique:users,email'],
            'phone'        => ['nullable', 'string'],
            'role'         => ['required', 'in:' . implode(',', User::ROLES)],
            'group'        => ['nullable', 'in:field,office,admin'],
            'zone'         => ['nullable', 'string'],
            'avail_status' => ['nullable', 'in:Available,On task,Assigned,At desk,Busy'],
            'workload'     => ['nullable', 'numeric', 'min:0'],
            'is_active'    => ['nullable', 'boolean'],
        ]);

        $user = User::create([
            'name'         => $data['name'],
            'email'        => $data['email'],
            'password'     => Hash::make('Hypermed@123'),
            'phone'        => $data['phone'] ?? null,
            'role'         => $data['role'],
            'staff_group'  => $data['group'] ?? null,
            'zone'         => $data['zone'] ?? null,
            'avail_status' => $data['avail_status'] ?? 'Available',
            'workload'     => $data['workload'] ?? 0.0,
            'is_active'    => $data['is_active'] ?? true,
            'avatar_initials' => collect(explode(' ', trim($data['name'])))->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
        ]);

        // Keep the legacy role column and Spatie's role assignment in sync —
        // the permission resolver reads Spatie roles, not this column directly.
        $user->syncRoles([$data['role']]);

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function show(User $user)
    {
        $user->load('currentTask');

        return response()->json(['data' => new UserResource($user)]);
    }

    public function update(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to edit staff accounts.');

        $data = $request->validate([
            'name'         => ['sometimes', 'string'],
            'phone'        => ['nullable', 'string'],
            'region'       => ['nullable', 'string'],
            'role'         => ['sometimes', 'in:' . implode(',', User::ROLES)],
            'group'        => ['nullable', 'in:field,office,admin'],
            'zone'         => ['nullable', 'string'],
            'avail_status' => ['sometimes', 'in:Available,On task,Assigned,At desk,Busy'],
            'workload'     => ['nullable', 'numeric', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
        ]);

        if (isset($data['group'])) {
            $data['staff_group'] = $data['group'];
            unset($data['group']);
        }

        $user->update($data);

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
            app(\App\Services\EffectivePermissionResolver::class)->invalidate($user);
        }

        return response()->json(['data' => new UserResource($user->load('currentTask'))]);
    }

    public function destroy(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to deactivate staff accounts.');

        $user->update(['is_active' => false]);

        return response()->json(['message' => 'Staff member deactivated.']);
    }

    // Resets a member's password back to the same default used when they're
    // first invited (see store() above) — the admin hands the member that
    // fixed password and they change it themselves afterward. Gated on
    // roles.manage, same as the "..." menu that surfaces this in the UI.
    public function resetPassword(Request $request, User $user, EffectivePermissionResolver $resolver)
    {
        abort_unless(
            $resolver->can($request->user(), 'authority.admin_tier') || $resolver->can($request->user(), 'roles.manage'),
            403,
            'You are not authorised to reset member passwords.'
        );

        $user->update(['password' => Hash::make('Hypermed@123')]);

        return response()->json(['message' => 'Password reset to the default.']);
    }

    public function updateAvailStatus(Request $request, User $user)
    {
        $data = $request->validate([
            'avail_status' => ['required', 'in:Available,On task,Assigned,At desk,Busy'],
            'workload'     => ['nullable', 'numeric', 'min:0'],
        ]);

        $user->update($data);

        return response()->json(['data' => new UserResource($user->load('currentTask'))]);
    }
}
