<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 2026_08_25's role-check-constraint migration widened users.role to accept
 * 'procurement_manager'/'accountant'/'logistics', but never created the
 * matching Spatie Role rows — and the very next day's permission catch-up
 * migration (2026_08_26_060000) assumed those rows already existed
 * ('if (! $role) continue' on every grant), so it silently no-op'd for all
 * three. Net effect on any database migrated before today: the three roles
 * don't exist in Spatie's `roles` table at all, so `syncRoles()` throws
 * RoleDoesNotExist for any user assigned one of them (500 on staff
 * create/update), and any user whose `users.role` string already reads one
 * of these three has zero actual permissions, since EffectivePermissionResolver
 * only ever reads Spatie's model_has_roles/role_has_permissions, never the
 * plain string column. Caught 2026-09-03 trying to promote a real
 * production user to 'accountant'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $roleNames = ['procurement_manager', 'accountant', 'logistics'];
        $roles = [];
        foreach ($roleNames as $name) {
            $roles[$name] = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $roleGrants = [
            'procurement_manager' => ['procurement.create_po', 'procurement.approve_requisition'],
            'accountant'          => ['procurement.initiate_payment'],
            'logistics'           => ['logistics.deliver_order', 'logistics.receive_order'],
        ];

        foreach ($roleGrants as $roleName => $keys) {
            foreach ($keys as $key) {
                $permission = Permission::where('name', $key)->first();
                if (! $permission) {
                    continue;
                }
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $roles[$roleName]->id, 'permission_id' => $permission->id],
                    ['scope' => 'all']
                );
            }
        }

        $screenGrants = [
            'procurement_manager' => ['dashboard', 'approvals', 'inventory', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'accountant'          => ['dashboard', 'approvals', 'revenue', 'finance', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'logistics'           => ['dashboard', 'inventory', 'sales', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
        ];

        foreach ($screenGrants as $roleName => $keys) {
            foreach ($keys as $key) {
                $permission = Permission::where('name', "screens.{$key}")->first();
                if (! $permission) {
                    continue;
                }
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $roles[$roleName]->id, 'permission_id' => $permission->id],
                    ['scope' => 'all']
                );
            }
        }

        // Backfill any user already sitting on one of these role strings
        // (via the plain `role` column) but never actually linked to the
        // Spatie role — they'd have hit the same syncRoles() failure at
        // creation/edit time and been silently left with zero permissions.
        foreach ($roleNames as $roleName) {
            User::where('role', $roleName)->get()->each(
                fn (User $u) => $u->syncRoles([$roleName])
            );
        }
    }

    public function down(): void
    {
        // Intentionally a no-op, same reasoning as 2026_08_26_060000 — this
        // only repairs a gap against PermissionSeeder.php's own source of
        // truth; rolling it back would reopen the bug for no benefit.
    }
};
