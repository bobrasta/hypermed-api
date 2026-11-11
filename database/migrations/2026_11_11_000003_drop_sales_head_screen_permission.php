<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

/**
 * The Head of Sales workspace is shown by role — sales_manager only (user,
 * 2026-11-11: "this design should only be seen by sales manager; everyone
 * else should have the old view"). The screens.sales_head permission from
 * 2026_11_11_000002 had also been given to admin/super_admin, which put the
 * new sidebar in front of the Director; it is no longer used, so it goes
 * (its role grants and Gabriel's override cascade with it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::where('name', 'screens.sales_head')->where('guard_name', 'web')->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }

    public function down(): void
    {
        // Nothing to restore: the workspace no longer reads this permission.
    }
};
