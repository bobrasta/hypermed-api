<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * New permissions for Section 13 (hypermed_claude_code_prompt.md): Receive
 * Machine and Allocate. Handover deliberately reuses the existing
 * services.sign_off_installation permission (already held by cto/team_leader)
 * rather than a new key — MachineController::signOff() already IS the
 * handover confirmation step for the sales-order delivery path, and this
 * extends it to the general Allocated->Installed transition too.
 *
 * PermissionSeeder.php's Role::count()===0 guard means catalog/grant edits
 * there don't reach an already-seeded database — same established pattern
 * as this session's other permission catch-up migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $receive = Permission::firstOrCreate(
            ['name' => 'machines.receive', 'guard_name' => 'web'],
            ['module' => 'machines', 'label' => 'Receive Machine', 'description' => 'Record new equipment arriving into a store, before it belongs to any hospital']
        );
        $allocate = Permission::firstOrCreate(
            ['name' => 'machines.allocate', 'guard_name' => 'web'],
            ['module' => 'machines', 'label' => 'Allocate Machine', 'description' => 'Reserve an In Stock machine for a hospital ahead of installation']
        );

        foreach (['super_admin', 'admin', 'storekeeper', 'sales_manager'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ([$receive, $allocate] as $permission) {
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permission->id],
                    ['scope' => 'all']
                );
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op, same reasoning as this session's other
        // permission catch-up migrations.
    }
};
