<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Splits final authorisation of money out of authority.admin_tier, so the
// CTO can be given admin-tier (a per-user override) without the Director's
// money sign-off — see User::hasFinalMoneyAuthority() and
// EffectivePermissionResolver::RESERVED. The Director's roles keep it.
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(
            ['name' => 'authority.final_money_approval', 'guard_name' => 'web'],
            [
                'module'      => 'authority',
                'label'       => 'Final Authorisation of Money',
                'description' => 'Director sign-off on expenses, purchase orders, payroll, salary changes, vendor bills/fees and credit notes; deleting approved money records; money approval routing',
            ]
        );
        foreach (Role::whereIn('name', ['super_admin', 'admin'])->get() as $role) {
            if (! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }
        $this->bust();
    }

    public function down(): void
    {
        Permission::where('name', 'authority.final_money_approval')->delete();
        $this->bust();
    }

    private function bust(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }
};
