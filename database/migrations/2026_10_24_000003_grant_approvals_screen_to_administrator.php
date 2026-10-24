<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// 2026-10-24: Mansoor (storekeeper) reports to Ansa ("Administration",
// role administrator), who now approves his expenses — and had no Approvals
// screen to do it from.
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'screens.approvals', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Approvals Screen']);
        $role = Role::where('name', 'administrator')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $this->forget();
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'screens.approvals')->first();
        $role = Role::where('name', 'administrator')->first();
        if ($permission && $role && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }
        $this->forget();
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::where('role', 'administrator')->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
