<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Same catch-up pattern as 2026_08_26_060000_... — PermissionSeeder's
 * Role::count()===0 guard means a permission added to the seeder file
 * after first bootstrap never actually gets created on an existing
 * database. Catches up 'services.sign_off_installation', added alongside
 * the equipment lineage tracking work (machines.pending_installation ->
 * pending_signoff -> operational).
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'services.sign_off_installation', 'guard_name' => 'web'],
            [
                'module'      => 'services',
                'label'       => 'Sign Off Equipment Installation',
                'description' => 'Confirm a newly delivered unit was installed correctly (not the installer themselves)',
            ]
        );

        foreach (['team_leader', 'cto', 'admin', 'super_admin'] as $roleName) {
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
