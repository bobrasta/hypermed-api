<?php

use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// ADMINISTRATOR — head of the Administrative Wing: the full HR package plus
// sales-staff work (no discount/order approvals), email, the org chart (edit)
// and the meetings register (secretary to every meeting). Money stays with
// the Director: she prepares payroll and salary changes, he approves.
//
// Built from whatever the live `hr` and `sales` roles hold at migration time
// (so Role Builder edits on production carry over), then the extras below.
// Reserved money/admin keys are never copied in. support@hypermed.co.tz
// ("Administration") moves onto it.
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'org_chart.manage' => ['module' => 'admin', 'label' => 'Edit Org Chart', 'description' => 'Edit the company org chart (everyone can view it)'],
        'meetings.manage'  => ['module' => 'admin', 'label' => 'Run Meetings & Minutes', 'description' => 'Schedule meetings, record attendance and minutes, assign action items, circulate minutes; see every meeting'],
        'screens.meetings' => ['module' => 'screens', 'label' => 'View Meetings Screen'],
    ];

    private const EXTRA_KEYS = [
        'screens.email', 'screens.notifications', 'screens.staff',
        'org_chart.manage', 'meetings.manage', 'screens.meetings',
    ];

    private const ADMINISTRATOR_EMAIL = 'support@hypermed.co.tz';

    public function up(): void
    {
        $perms = [];
        foreach (self::NEW_PERMISSIONS as $name => $attrs) {
            $perms[$name] = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], $attrs);
        }
        foreach (Role::whereIn('name', ['super_admin', 'admin'])->get() as $role) {
            foreach ($perms as $p) {
                if (! $role->hasPermissionTo($p)) {
                    $role->givePermissionTo($p);
                }
            }
        }

        $role = Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web'], ['is_system' => true]);

        $sourceIds = Role::whereIn('name', ['hr', 'sales'])->pluck('id');
        $grants = DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->whereIn('role_has_permissions.role_id', $sourceIds)
            ->whereNotIn('permissions.name', [...EffectivePermissionResolver::RESERVED, 'authority.admin_tier'])
            ->get(['permissions.id', 'role_has_permissions.scope']);

        $scopes = [];
        foreach ($grants as $g) {
            // Two roles granting the same key: keep the wider scope.
            $scope = $g->scope ?? 'all';
            $scopes[$g->id] = ($scopes[$g->id] ?? null) === 'all' || $scope === 'all' ? 'all' : ($scopes[$g->id] ?? $scope);
        }
        foreach (Permission::whereIn('name', self::EXTRA_KEYS)->pluck('id') as $id) {
            $scopes[$id] = 'all';
        }
        foreach ($scopes as $permissionId => $scope) {
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permissionId],
                ['scope' => $scope]
            );
        }

        $user = User::where('email', self::ADMINISTRATOR_EMAIL)->first();
        if ($user) {
            $user->update(['role' => 'administrator']);
            $user->syncRoles(['administrator']);
        }

        $this->bust();
    }

    public function down(): void
    {
        $user = User::where('email', self::ADMINISTRATOR_EMAIL)->where('role', 'administrator')->first();
        if ($user && Role::where('name', 'Sales and Administration')->exists()) {
            $user->update(['role' => 'Sales and Administration']);
            $user->syncRoles(['Sales and Administration']);
        }
        Role::where('name', 'administrator')->delete();
        Permission::whereIn('name', array_keys(self::NEW_PERMISSIONS))->delete();
        $this->bust();
    }

    private function bust(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }
};
