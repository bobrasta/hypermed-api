<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * A technician does the repair work, not staff task assignment or stock
 * management — but the technician role held screens.staff and
 * screens.inventory (visible sidebar sections, and — now that
 * StaffController::index()/show() are actually gated behind
 * screens.staff — real backend access too). PermissionSeeder.php's
 * catalog is updated to match; this migration carries the fix to an
 * already-seeded database, same pattern as
 * 2026_09_03_132127_revoke_staff_screen_from_accountant_role.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('name', 'technician')->first();
        if (! $role) {
            return;
        }

        foreach (['screens.staff', 'screens.inventory'] as $permName) {
            $permission = Permission::where('name', $permName)->first();
            if (! $permission) {
                continue;
            }
            DB::table('role_has_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->delete();
        }
    }

    public function down(): void
    {
        $role = Role::where('name', 'technician')->first();
        if (! $role) {
            return;
        }

        foreach (['screens.staff', 'screens.inventory'] as $permName) {
            $permission = Permission::where('name', $permName)->first();
            if (! $permission) {
                continue;
            }
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permission->id],
                ['scope' => 'all']
            );
        }
    }
};
