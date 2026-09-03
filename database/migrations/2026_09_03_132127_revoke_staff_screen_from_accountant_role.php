<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The accountant role was originally granted screens.staff (the
 * task-assignment board) alongside every other role, matching the older
 * "everyone gets staff/my_leave" convention — but accountant handles
 * payments, not staff task assignment, and shouldn't see or assign work
 * on that board. PermissionSeeder.php's own catalog is updated to match
 * (won't re-run on an already-seeded database, so this migration carries
 * the fix to production).
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = Role::where('name', 'accountant')->first();
        $permission = Permission::where('name', 'screens.staff')->first();

        if ($role && $permission) {
            DB::table('role_has_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $permission->id)
                ->delete();
        }
    }

    public function down(): void
    {
        $role = Role::where('name', 'accountant')->first();
        $permission = Permission::where('name', 'screens.staff')->first();

        if ($role && $permission) {
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permission->id],
                ['scope' => 'all']
            );
        }
    }
};
