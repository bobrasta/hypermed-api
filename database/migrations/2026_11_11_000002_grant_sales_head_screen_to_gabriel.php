<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Head of Sales workspace (2026-11-11): its own sidebar and pages, behind
 * screens.sales_head. Given to Gabriel as a per-user override, not to the
 * sales_manager role — another sales manager keeps the normal Sales menu.
 * admin/super_admin get it on the role, like every other screen key
 * (seedScreenPermissions() is a no-op on a seeded DB, so this does it).
 */
return new class extends Migration
{
    private const HEAD_EMAIL = 'gabrielbenedict@hypermed.co.tz';

    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'screens.sales_head', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Sales Head Screen']
        );
        foreach (Role::whereIn('name', ['super_admin', 'admin'])->get() as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $head = User::where('email', self::HEAD_EMAIL)->first();
        if ($head) {
            DB::table('user_permission_overrides')->updateOrInsert(
                ['user_id' => $head->id, 'permission_id' => $permission->id],
                ['effect' => 'allow', 'reason' => 'Head of Sales', 'created_at' => now()]
            );
        }
        $this->forget($head);
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'screens.sales_head')->first();
        $head = User::where('email', self::HEAD_EMAIL)->first();
        if ($permission) {
            DB::table('user_permission_overrides')->where('permission_id', $permission->id)->delete();
            $permission->delete();
        }
        $this->forget($head);
    }

    private function forget(?User $head): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::whereIn('role', ['super_admin', 'admin'])->pluck('id')->push($head?->id)->filter() as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }
};
