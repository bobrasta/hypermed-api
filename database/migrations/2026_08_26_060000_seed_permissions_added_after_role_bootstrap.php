<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * PermissionSeeder::run()'s catalog+grants block only executes when
 * Role::count() === 0 (so re-running the seeder after initial bootstrap
 * never clobbers Role Builder edits) — but that also means every permission
 * added to the seeder file *after* a database's first seed run (production
 * included) never actually gets created there, even though the seeder file
 * itself is correct for a brand-new database. Hit this directly on
 * 2026-08-26 testing the new staff.manage permission locally. This
 * migration is the catch-up for every permission added this way today:
 * procurement.*, logistics.*, staff.manage, plus the screens.* grants for
 * the 3 new roles (seedScreenPermissions() has its own guard that also
 * no-ops once 'screens.notifications' exists, i.e. on any real database).
 *
 * Safe to run on a database that already has these (idempotent — every
 * write below is firstOrCreate/updateOrInsert), and safe on a database
 * that's never seeded at all (each grant is skipped if its role doesn't
 * exist yet, e.g. a fresh DB where PermissionSeeder hasn't run yet since
 * it runs after migrate in the deploy command).
 */
return new class extends Migration
{
    public function up(): void
    {
        $catalog = [
            'procurement.create_po'             => ['procurement', 'Create Purchase Orders', 'Draft a purchase order and pick a vendor/supplier'],
            'procurement.approve_requisition'   => ['procurement', 'Approve Purchase Requisitions', 'Approve a submitted purchase requisition'],
            'procurement.approve_po_sales_stage'=> ['procurement', 'Approve PO — Sales Stage', 'First-stage purchase-order approval (sales/commercial review)'],
            'procurement.approve_po_director_stage' => ['procurement', 'Approve PO — Director Stage', 'Second-stage purchase-order approval (director review)'],
            'procurement.initiate_payment'      => ['procurement', 'Initiate PO Payment', 'Mark a purchase order as ready for/undergoing payment'],
            'procurement.approve_payment_final' => ['procurement', 'Final PO Payment Approval', 'Final director sign-off after payment has been initiated'],
            'logistics.deliver_order'           => ['logistics', 'Deliver Sales Orders', 'Mark a sales order delivered to the customer'],
            'logistics.receive_order'           => ['logistics', 'Receive Purchase Orders', 'Mark a purchase order received from the vendor'],
            'staff.manage'                      => ['admin', 'Manage Staff', "Create, edit, and deactivate staff accounts, including changing a member's role"],
        ];

        $permissions = [];
        foreach ($catalog as $key => [$module, $label, $description]) {
            $permissions[$key] = Permission::firstOrCreate(
                ['name' => $key, 'guard_name' => 'web'],
                ['module' => $module, 'label' => $label, 'description' => $description]
            );
        }
        // roles.manage predates today's catalog additions — look it up
        // rather than assuming it's in $permissions above.
        $permissions['roles.manage'] = Permission::where('name', 'roles.manage')->first();

        $roleGrants = [
            'procurement_manager' => ['procurement.create_po', 'procurement.approve_requisition'],
            'sales_manager'       => ['procurement.approve_po_sales_stage'],
            'accountant'          => ['procurement.initiate_payment'],
            'logistics'           => ['logistics.deliver_order', 'logistics.receive_order'],
            'storekeeper'         => ['logistics.receive_order'],
            'hr'                  => ['staff.manage', 'roles.manage'],
            'admin'               => array_keys($catalog),
            'super_admin'         => array_keys($catalog),
        ];

        foreach ($roleGrants as $roleName => $keys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($keys as $key) {
                if (! isset($permissions[$key])) {
                    continue;
                }
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permissions[$key]->id],
                    ['scope' => 'all']
                );
            }
        }

        // screens.* grants for the 3 new roles — seedScreenPermissions() no-ops
        // once 'screens.notifications' exists (true on any pre-existing DB),
        // so it never ran for these roles even though PermissionSeeder.php
        // itself lists the grants correctly.
        $screenGrants = [
            'procurement_manager' => ['dashboard', 'approvals', 'inventory', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'accountant'          => ['dashboard', 'approvals', 'revenue', 'finance', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'logistics'           => ['dashboard', 'inventory', 'sales', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
        ];

        foreach ($screenGrants as $roleName => $keys) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($keys as $key) {
                $perm = Permission::where('name', "screens.{$key}")->first();
                if (! $perm) {
                    continue;
                }
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $perm->id],
                    ['scope' => 'all']
                );
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op — this only ever grants permissions that
        // PermissionSeeder.php itself defines; rolling it back would leave
        // the seeder's own source of truth out of sync with the database
        // for no real benefit.
    }
};
