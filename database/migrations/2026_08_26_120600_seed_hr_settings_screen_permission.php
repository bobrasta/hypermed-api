<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Same catch-up pattern as 2026_08_26_060000_... — PermissionSeeder's
 * seedScreenPermissions() no-ops once 'screens.notifications' exists (true
 * on any pre-existing DB), so the new 'hr_settings' screen key added to
 * PermissionSeeder.php today never actually gets created/granted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.hr_settings', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Hr Settings Screen']
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
    }

    public function down(): void
    {
        // Intentionally a no-op — see 2026_08_26_060000_... for the reasoning.
    }
};
