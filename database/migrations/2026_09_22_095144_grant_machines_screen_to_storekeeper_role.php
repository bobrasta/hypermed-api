<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * PermissionSeeder's seedScreenPermissions() early-returns once
 * screens.notifications already exists (true in every environment this
 * runs against), so editing its storekeeper grant list alone is a no-op
 * on an already-seeded DB — the recurring pattern this codebase has hit
 * twice before with staff.manage/hospitals.manage. storekeeper has held
 * machines.receive/allocate authority (User::hasMachineReceiveAuthority()/
 * hasMachineAllocateAuthority()) since Section 13 shipped, but had no
 * screens.machines grant, so no nav path to the Machines screen at all
 * on either platform — bienhypermed's allowedScreenKeys() and
 * hypermed-web's Nav::allowedKeys() only fall back to their hardcoded
 * tables when a user holds NONE of the live screens.* permissions, and
 * storekeeper already holds several (screens.inventory etc.), so those
 * hardcoded-table edits alone never took effect for a real storekeeper
 * user — this migration is the actual fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.machines', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Machines Screen']
        );

        $role = Role::where('name', 'storekeeper')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }

        // Spatie's own internal permission cache, separate from
        // EffectivePermissionResolver's per-user 600s cache below.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Clear every storekeeper's cached effective-permission set
        // directly so the grant is visible immediately, rather than
        // waiting up to 10 minutes for EffectivePermissionResolver's own
        // TTL to expire naturally.
        foreach (User::where('role', 'storekeeper')->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }

    public function down(): void
    {
        $role = Role::where('name', 'storekeeper')->first();
        $permission = Permission::where('name', 'screens.machines')->first();
        if ($role && $permission && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::where('role', 'storekeeper')->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
