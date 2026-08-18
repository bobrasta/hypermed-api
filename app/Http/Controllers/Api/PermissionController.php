<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionController extends Controller
{
    // Any authenticated user — this is what the Flutter client (and any
    // future web frontend) syncs on login/resume to drive UI gating.
    public function me(Request $request, EffectivePermissionResolver $resolver)
    {
        return response()->json(['data' => $resolver->resolve($request->user())->values()]);
    }

    // Full catalog grouped by module, for the Role Builder's permission grid.
    public function permissions()
    {
        $grouped = Permission::orderBy('module')->orderBy('name')->get()
            ->groupBy('module')
            ->map(fn ($perms) => $perms->map(fn ($p) => [
                'id'          => $p->id,
                'key'         => $p->name,
                'label'       => $p->label,
                'description' => $p->description,
            ])->values());

        return response()->json(['data' => $grouped]);
    }

    public function roles()
    {
        $roles = Role::withCount('permissions')->orderBy('name')->get()->map(fn ($r) => [
            'id'               => $r->id,
            'name'             => $r->name,
            'is_system'        => $r->is_system,
            'permission_count' => $r->permissions_count,
            'permission_keys'  => $r->permissions()->pluck('name'),
        ]);

        return response()->json(['data' => $roles]);
    }

    public function storeRole(Request $request, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage roles.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:roles,name'],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web', 'is_system' => false]);

        return response()->json(['data' => [
            'id' => $role->id, 'name' => $role->name, 'is_system' => false, 'permission_count' => 0, 'permission_keys' => [],
        ]], 201);
    }

    public function updateRole(Request $request, Role $role, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage roles.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'unique:roles,name,' . $role->id],
        ]);

        $role->update($data);

        return response()->json(['data' => ['id' => $role->id, 'name' => $role->name, 'is_system' => $role->is_system]]);
    }

    public function destroyRole(Request $request, Role $role, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage roles.');
        abort_if($role->is_system, 422, 'System roles cannot be deleted — edit their permission set instead.');

        $role->delete();

        return response()->json(null, 204);
    }

    // Full replace of a role's permission set (+ per-permission scope) —
    // simpler and less error-prone than diffing add/remove for a checkbox grid.
    public function syncRolePermissions(Request $request, Role $role, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage roles.');

        $data = $request->validate([
            'permissions'         => ['present', 'array'],
            'permissions.*.key'   => ['required', 'string', 'exists:permissions,name'],
            'permissions.*.scope' => ['nullable', 'string', 'in:none,masked,own,team,all'],
        ]);

        DB::transaction(function () use ($role, $data) {
            DB::table('role_has_permissions')->where('role_id', $role->id)->delete();

            $permissionIds = Permission::whereIn('name', array_column($data['permissions'], 'key'))
                ->pluck('id', 'name');

            foreach ($data['permissions'] as $entry) {
                DB::table('role_has_permissions')->insert([
                    'role_id'       => $role->id,
                    'permission_id' => $permissionIds[$entry['key']],
                    'scope'         => $entry['scope'] ?? 'all',
                ]);
            }
        });

        // Best-effort immediate bust for every user currently holding this
        // role — the resolver's 10-minute TTL is the safety net if this misses.
        User::whereHas('roles', fn ($q) => $q->where('roles.id', $role->id))
            ->get()
            ->each(fn (User $u) => $resolver->invalidate($u));

        return response()->json(['data' => ['id' => $role->id, 'name' => $role->name]]);
    }

    // Effective permissions for an arbitrary user (admin lookup) — same shape
    // as me(), reusing the same resolver so the two can never disagree.
    public function userPermissions(Request $request, User $user, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to view member permissions.');

        return response()->json(['data' => $resolver->resolve($user)->values()]);
    }

    public function userOverrides(Request $request, User $user, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to view member permissions.');

        $overrides = DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->leftJoin('users as creators', 'creators.id', '=', 'user_permission_overrides.created_by')
            ->where('user_permission_overrides.user_id', $user->id)
            ->select(
                'user_permission_overrides.id',
                'permissions.name as key',
                'permissions.label',
                'user_permission_overrides.effect',
                'user_permission_overrides.scope',
                'user_permission_overrides.reason',
                'creators.name as created_by_name',
                'user_permission_overrides.created_at',
            )
            ->orderByDesc('user_permission_overrides.created_at')
            ->get();

        return response()->json(['data' => $overrides]);
    }

    public function storeUserOverride(Request $request, User $user, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage member permissions.');

        $data = $request->validate([
            'key'    => ['required', 'string', 'exists:permissions,name'],
            'effect' => ['required', 'in:allow,deny'],
            'scope'  => ['nullable', 'string', 'in:none,masked,own,team,all'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $permission = Permission::where('name', $data['key'])->firstOrFail();

        DB::table('user_permission_overrides')->updateOrInsert(
            ['user_id' => $user->id, 'permission_id' => $permission->id],
            [
                'effect'     => $data['effect'],
                'scope'      => $data['scope'] ?? null,
                'reason'     => $data['reason'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now(),
            ]
        );

        $resolver->invalidate($user);

        return response()->json(['data' => ['user_id' => $user->id, 'key' => $data['key'], 'effect' => $data['effect']]], 201);
    }

    public function destroyUserOverride(Request $request, User $user, int $override, EffectivePermissionResolver $resolver)
    {
        abort_if(! $resolver->can($request->user(), 'roles.manage'), 403, 'You are not authorised to manage member permissions.');

        DB::table('user_permission_overrides')
            ->where('id', $override)
            ->where('user_id', $user->id)
            ->delete();

        $resolver->invalidate($user);

        return response()->json(null, 204);
    }
}
