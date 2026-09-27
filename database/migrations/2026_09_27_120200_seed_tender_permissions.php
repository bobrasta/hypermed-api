<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

// Section 19.7. Granted here directly rather than only via PermissionSeeder,
// whose already-seeded guard makes new catalog entries a no-op in production
// (see the vendor-fees permission migration for the same fix).
//  - tenders.manage: create/edit tenders + device registrations, generate
//    documents, upload executed copies (procurement staff; admin tier).
//  - screens.tenders: see the Tenders & Contracts / Device Registrations
//    screens — also the CTO, who gets read-only access. The Director
//    (super_admin) and admin are admin-tier and see every screen already.
return new class extends Migration
{
    private const GRANTS = [
        'tenders.manage'  => ['procurement_manager', 'super_admin', 'admin'],
        'screens.tenders' => ['procurement_manager', 'cto', 'super_admin', 'admin'],
    ];

    private const LABELS = [
        'tenders.manage'  => ['module' => 'procurement', 'label' => 'Manage Tenders & Device Registrations'],
        'screens.tenders' => ['module' => 'screens', 'label' => 'View Tenders & Contracts Screen'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $roles) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], self::LABELS[$name]);
            foreach (Role::whereIn('name', $roles)->get() as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }
        $this->bust();
    }

    public function down(): void
    {
        foreach (array_keys(self::GRANTS) as $name) {
            Permission::where('name', $name)->delete();
        }
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
