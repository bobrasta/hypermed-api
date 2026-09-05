<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Two related fixes discovered auditing the technician role's real
 * authorization (2026-09-05):
 *
 * 1. Hospital create/edit/delete had NO backend gate at all — any
 *    authenticated user could call store()/update()/destroy(). Adds
 *    hospitals.manage as a real, Role-Builder-assignable permission
 *    (not a hardcoded role list) granted by default to super_admin/
 *    admin/cto, per the user's explicit request — a Director/CTO can
 *    hand it to a specific person via the Role Builder without a code
 *    change. PermissionSeeder.php's Role::count()===0 guard means this
 *    catalog addition needs a migration to actually reach an
 *    already-seeded database (established pattern this session).
 *
 * 2. services.close_ticket existed in the catalog but was never wired
 *    to any actual check — and was granted to 'technician', the exact
 *    role that should NOT be able to resolve a ticket (that's CTO/
 *    Director work). Repoints it: revoked from technician, granted to
 *    cto/team_leader (super_admin/admin already hold every permission
 *    via PermissionSeeder's full-access loop, so no explicit grant
 *    needed for them here — this only needs to reach an existing DB
 *    where that loop already ran).
 */
return new class extends Migration
{
    public function up(): void
    {
        $hospitalsManage = Permission::firstOrCreate(
            ['name' => 'hospitals.manage', 'guard_name' => 'web'],
            ['module' => 'hospitals', 'label' => 'Manage Hospitals', 'description' => 'Create, edit, and delete hospital records']
        );

        $closeTicket = Permission::where('name', 'services.close_ticket')->first();

        foreach (['cto'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $hospitalsManage->id],
                ['scope' => 'all']
            );
        }

        if ($closeTicket) {
            $technician = Role::where('name', 'technician')->first();
            if ($technician) {
                DB::table('role_has_permissions')
                    ->where('role_id', $technician->id)
                    ->where('permission_id', $closeTicket->id)
                    ->delete();
            }

            foreach (['cto', 'team_leader'] as $roleName) {
                $role = Role::where('name', $roleName)->first();
                if (! $role) {
                    continue;
                }
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $closeTicket->id],
                    ['scope' => 'all']
                );
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op, same reasoning as this session's other
        // permission catch-up migrations — this only repairs a gap against
        // PermissionSeeder.php's own source of truth; rolling it back would
        // reopen the bug for no benefit.
    }
};
