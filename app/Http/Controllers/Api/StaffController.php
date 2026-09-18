<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Contract;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // Anyone who can manage staff (create/edit/deactivate, e.g. HR) can
        // certainly view the roster too — hasStaffViewAuthority() alone
        // (screens.staff) was leaving HR, which has staff.manage but not
        // screens.staff by design (that's Operations' task-board key), 403'd
        // out of its own dashboard's staff count.
        if ($user->hasStaffViewAuthority() || $user->hasStaffManageAuthority()) {
            $staff = User::with(['currentTask', 'position'])->where('is_active', true)->get();

            return UserResource::collection($staff);
        }

        // A sales_manager without the full roster permission still gets a
        // scoped "my team" view — sales.create_subordinate_user grants
        // seeing/building their own reports, never the company roster.
        if ($user->hasSalesCreateSubordinateAuthority()) {
            $staff = User::with(['currentTask', 'position'])
                ->where('is_active', true)
                ->where('manager_id', $user->id)
                ->get();

            return UserResource::collection($staff);
        }

        abort(403, 'Access Denied: you do not have permission to view the staff roster.');
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $canManageStaff = $user->hasStaffManageAuthority();
        $canCreateSubordinate = $user->hasSalesCreateSubordinateAuthority();
        abort_if(! $canManageStaff && ! $canCreateSubordinate, 403, 'You are not authorised to create staff accounts.');

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
            'manager_id'   => ['nullable', 'exists:users,id'],
            'position_id'  => ['nullable', 'exists:positions,id'],
            'gender'       => ['nullable', 'in:male,female'],
            'hire_date'    => ['nullable', 'date'],
            'next_of_kin_name'         => ['nullable', 'string'],
            'next_of_kin_phone'        => ['nullable', 'string'],
            'next_of_kin_relationship' => ['nullable', 'string'],
            'nssf_number'  => ['nullable', 'string'],
            'tin_number'   => ['nullable', 'string'],
            'nida_number'  => ['nullable', 'string'],
            'biometric_id' => ['nullable', 'string', 'unique:users,biometric_id'],
            'base_salary'  => ['nullable', 'integer', 'min:0'],
        ]);

        // staff.manage (held by hr as well as admin/super_admin) is broad
        // enough for ordinary record-keeping, but must not let it double as
        // a way to mint or grant an admin-tier account — that stays gated
        // to Director authority specifically, same segregation-of-duty
        // reasoning as SalaryAdjustment's approval-only self-escalation gap.
        abort_if(
            in_array($data['role'], User::ADMIN_TIER, true) && ! $user->hasDirectorAuthority(),
            403,
            'Only a Director can create an admin-tier account.',
        );

        // A sales_manager building their own team can only ever create a
        // 'sales' rep reporting to themselves — never an arbitrary role or
        // a report elsewhere in the org. staff.manage (HR/admin) is
        // unrestricted, as before.
        if (! $canManageStaff && $canCreateSubordinate) {
            $data['role'] = 'sales';
            $data['manager_id'] = $user->id;
        }

        $user = DB::transaction(function () use ($data, $canManageStaff, $request) {
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
                'manager_id'   => $data['manager_id'] ?? null,
                'position_id'  => $data['position_id'] ?? null,
                'gender'       => $data['gender'] ?? null,
                'hire_date'    => $data['hire_date'] ?? null,
                'next_of_kin_name'         => $data['next_of_kin_name'] ?? null,
                'next_of_kin_phone'        => $data['next_of_kin_phone'] ?? null,
                'next_of_kin_relationship' => $data['next_of_kin_relationship'] ?? null,
                'nssf_number'  => $data['nssf_number'] ?? null,
                'tin_number'   => $data['tin_number'] ?? null,
                'nida_number'  => $data['nida_number'] ?? null,
                'biometric_id' => $data['biometric_id'] ?? null,
                'avatar_initials' => collect(explode(' ', trim($data['name'])))->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
            ]);

            // Keep the legacy role column and Spatie's role assignment in sync —
            // the permission resolver reads Spatie roles, not this column directly.
            $user->syncRoles([$data['role']]);

            // Only staff.manage (HR/admin) can set an opening salary here —
            // a sales_manager creating their own rep (canCreateSubordinate)
            // must never be able to set anyone's pay, mirroring the
            // Director-only gate on SalaryAdjustment approval. A minimal
            // real Contract row (not a schema hack) so it shows up
            // correctly everywhere Contract is already read; HR can still
            // open the full Contracts panel later to formalise dates/type.
            if ($canManageStaff && ! empty($data['base_salary'])) {
                Contract::create([
                    'user_id'       => $user->id,
                    'contract_type' => 'permanent',
                    'start_date'    => $data['hire_date'] ?? now()->toDateString(),
                    'base_salary'   => $data['base_salary'],
                    'status'        => 'active',
                    'created_by'    => $request->user()->id,
                ]);
            }

            return $user;
        });

        return response()->json(['data' => new UserResource($user)], 201);
    }

    public function show(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffViewAuthority(), 403,
            'Access Denied: you do not have permission to view staff details.');

        $user->load(['currentTask', 'position']);

        return response()->json(['data' => new UserResource($user)]);
    }

    public function update(Request $request, User $user)
    {
        abort_if(! $request->user()->hasStaffManageAuthority(), 403, 'You are not authorised to edit staff accounts.');

        $data = $request->validate([
            'name'         => ['sometimes', 'string'],
            'email'        => ['sometimes', 'email', 'unique:users,email,' . $user->id],
            'phone'        => ['nullable', 'string'],
            'region'       => ['nullable', 'string'],
            'role'         => ['sometimes', 'in:' . implode(',', User::ROLES)],
            'group'        => ['nullable', 'in:field,office,admin'],
            'zone'         => ['nullable', 'string'],
            'avail_status' => ['sometimes', 'in:Available,On task,Assigned,At desk,Busy'],
            'workload'     => ['nullable', 'numeric', 'min:0'],
            'is_active'    => ['sometimes', 'boolean'],
            'manager_id'   => ['nullable', 'exists:users,id'],
            'position_id'  => ['nullable', 'exists:positions,id'],
            'gender'       => ['nullable', 'in:male,female'],
            'hire_date'    => ['nullable', 'date'],
            'next_of_kin_name'         => ['nullable', 'string'],
            'next_of_kin_phone'        => ['nullable', 'string'],
            'next_of_kin_relationship' => ['nullable', 'string'],
            'nssf_number'  => ['nullable', 'string'],
            'tin_number'   => ['nullable', 'string'],
            'nida_number'  => ['nullable', 'string'],
            'biometric_id' => ['nullable', 'string', 'unique:users,biometric_id,' . $user->id],
        ]);

        if (isset($data['group'])) {
            $data['staff_group'] = $data['group'];
            unset($data['group']);
        }

        if (array_key_exists('manager_id', $data) && $data['manager_id'] === $user->id) {
            abort(422, 'A staff member cannot be their own manager.');
        }

        // Position changes are a director-level call (org structure), not a
        // routine HR edit — HR can see/manage everything else on this form,
        // but only admin tier can move someone's position. Also covers
        // career-progression style changes made through this endpoint
        // directly rather than PositionChangeController.
        if (array_key_exists('position_id', $data) && $data['position_id'] !== $user->position_id
            && ! $request->user()->hasDirectorAuthority()) {
            abort(403, 'Only a Director/Admin can change a staff member\'s position.');
        }

        $user->update($data);

        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
            app(\App\Services\EffectivePermissionResolver::class)->invalidate($user);
        }

        return response()->json(['data' => new UserResource($user->load(['currentTask', 'position']))]);
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
