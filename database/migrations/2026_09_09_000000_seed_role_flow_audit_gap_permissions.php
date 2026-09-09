<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Catch-up for two new permissions added while closing gaps found in a
 * fresh 2026-09-08 role-flow audit (5 domains re-traced against live code):
 *
 * 1. inventory.manage_catalog — item/supplier/location CRUD and raw
 *    stock-movement posting had NO gate at all. Granted to
 *    storekeeper/procurement_manager, who already hold the 'inventory'
 *    screen and operationally touch this data.
 *
 * 2. tasks.manage_board — the general Task board (/tasks) used
 *    authority.manager_tier (super_admin/admin/sales_manager/
 *    finance_manager only), leaving cto/team_leader — who supervise
 *    technicians and hold equivalent authority everywhere else in the
 *    service-ticket world — unable to create/reassign a task despite
 *    seeing the board. A separate permission rather than widening
 *    manager_tier itself, which other call sites already depend on
 *    meaning what it currently means.
 *
 * PermissionSeeder.php's Role::count()===0 guard means catalog/grant
 * edits there don't reach an already-seeded database — same established
 * pattern as this session's other permission catch-up migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        $manageCatalog = Permission::firstOrCreate(
            ['name' => 'inventory.manage_catalog', 'guard_name' => 'web'],
            ['module' => 'inventory', 'label' => 'Manage Inventory Catalog', 'description' => 'Create/edit/delete items, suppliers, locations, and post stock movements directly']
        );

        foreach (['super_admin', 'admin', 'storekeeper', 'procurement_manager'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $manageCatalog->id],
                ['scope' => 'all']
            );
        }

        $taskManage = Permission::firstOrCreate(
            ['name' => 'tasks.manage_board', 'guard_name' => 'web'],
            ['module' => 'authority', 'label' => 'Manage Task Board', 'description' => 'Create, reassign, and delete general (non-ticket) staff tasks']
        );

        foreach (['super_admin', 'admin', 'sales_manager', 'finance_manager', 'cto', 'team_leader'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            DB::table('role_has_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $taskManage->id],
                ['scope' => 'all']
            );
        }
    }

    public function down(): void
    {
        // Intentionally a no-op, same reasoning as this session's other
        // permission catch-up migrations.
    }
};
