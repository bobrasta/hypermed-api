<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Same recurring gap this codebase has hit repeatedly now (storekeeper's
 * screens.machines, staff.manage, hospitals.manage): PermissionSeeder's
 * seedScreenPermissions() early-returns once screens.notifications already
 * exists, so adding 'vendor_fees' to its source-list grants alone is a
 * no-op on the already-seeded production DB. Section 16's Flutter/web nav
 * entries for Vendor Fees need this permission to actually appear for
 * procurement_manager/logistics/accountant/finance_manager/finance — the
 * same set VendorFeeController::assertOpsAccess() actually authorizes
 * (admin-tier already sees everything via authority.admin_tier).
 */
return new class extends Migration
{
    private const ROLES = ['procurement_manager', 'logistics', 'accountant', 'finance_manager', 'finance'];

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.vendor_fees', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Vendor Fees Screen']
        );

        foreach (Role::whereIn('name', self::ROLES)->get() as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::whereIn('role', self::ROLES)->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'screens.vendor_fees')->first();
        if ($permission) {
            foreach (Role::whereIn('name', self::ROLES)->get() as $role) {
                if ($role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::whereIn('role', self::ROLES)->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
