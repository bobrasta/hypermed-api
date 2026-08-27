<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Same catch-up pattern as 2026_08_26_060000_.../120600_... —
 * seedScreenPermissions() no-ops once 'screens.notifications' exists, so
 * today's new 'hr_staff' screen key never gets created/granted via the
 * seeder on an already-bootstrapped DB. Also explicitly revokes 'hr'
 * role's grant of the operational 'screens.staff' (task-assignment board)
 * permission — corrected 2026-08-27: HR should not see or use that
 * screen, only its own hr_staff directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.hr_staff', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Hr Staff Screen']
        );

        foreach (['hr', 'admin', 'super_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permission->id],
                ['scope' => 'all']
            );
        }

        $hrRole = Role::where('name', 'hr')->first();
        $staffScreenPermission = Permission::where('name', 'screens.staff')->first();
        if ($hrRole && $staffScreenPermission) {
            DB::table('role_has_permissions')
                ->where('role_id', $hrRole->id)
                ->where('permission_id', $staffScreenPermission->id)
                ->delete();
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — see 2026_08_26_060000_... for the reasoning.
    }
};
