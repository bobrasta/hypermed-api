<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Staff contributions is the Director's screen — and the Director's account
 * (Kiduduye) holds the 'admin' role, not 'super_admin', so 2026_10_23_000004
 * left them without it. Grant it to admin as well.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::where('name', 'screens.contributions')->first();
        $role = Role::where('name', 'admin')->first();
        if ($permission && $role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $this->forget();
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'screens.contributions')->first();
        $role = Role::where('name', 'admin')->first();
        if ($permission && $role && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }
        $this->forget();
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::where('role', 'admin')->pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
