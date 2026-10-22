<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The finance accountant raises and edits sales (hasAccountantAuthority) and
 * lists invoices, but had no screens.sales, so the whole Sales menu (All
 * sales, drafts, quotations, invoices) was hidden from them (user,
 * 2026-10-22: "the Finance guy should see the all sales section"). Same
 * three layers as 2026_09_22_095144: seeder source list, this direct grant
 * (seedScreenPermissions() is a no-op on a seeded DB), and cache busts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.sales', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Sales Screen']
        );
        $role = Role::where('name', 'accountant')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $this->forget();
    }

    public function down(): void
    {
        $role = Role::where('name', 'accountant')->first();
        $permission = Permission::where('name', 'screens.sales')->first();
        if ($role && $permission && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }
        $this->forget();
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::where('role', 'accountant')->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
