<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Same catch-up pattern as 2026_08_27_130000_... — seedScreenPermissions()
 * no-ops once 'screens.notifications' exists, so today's new HR nav
 * restructuring (one tabbed 'hr_staff' screen split into 7 flat top-level
 * destinations: Dashboard, Directory, Recruitment, Leave Calendar,
 * Attendance, Payroll, Reports) never reaches an already-bootstrapped DB
 * via the seeder alone. Grants the 7 new keys and revokes the retired
 * 'screens.hr_staff'.
 */
return new class extends Migration
{
    private const NEW_KEYS = [
        'hr_dashboard', 'hr_directory', 'hr_recruitment',
        'hr_leave_calendar', 'hr_attendance', 'hr_payroll', 'hr_reports',
    ];

    public function up(): void
    {
        $permissions = [];
        foreach (self::NEW_KEYS as $key) {
            $permissions[] = Permission::firstOrCreate(
                ['name' => "screens.{$key}", 'guard_name' => 'web'],
                ['module' => 'screens', 'label' => 'View '.ucwords(str_replace('_', ' ', $key)).' Screen']
            );
        }

        foreach (['hr', 'admin', 'super_admin'] as $roleName) {
            $role = Role::where('name', $roleName)->first();
            if (! $role) {
                continue;
            }
            foreach ($permissions as $permission) {
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permission->id],
                    ['scope' => 'all']
                );
            }
        }

        $hrRole = Role::where('name', 'hr')->first();
        $oldPermission = Permission::where('name', 'screens.hr_staff')->first();
        if ($hrRole && $oldPermission) {
            DB::table('role_has_permissions')
                ->where('role_id', $hrRole->id)
                ->where('permission_id', $oldPermission->id)
                ->delete();
        }

        // Writing role_has_permissions via DB::table() (not the Eloquent
        // givePermissionTo()/revokePermissionTo() relationship methods)
        // skips Spatie's model-event cache-flush hook — its 24h
        // 'spatie.permission.cache' entry would otherwise keep serving the
        // old grant set until it expires. Found live in this session: HR
        // still saw 'screens.hr_staff' and none of the new keys via
        // /me/permissions immediately after this migration ran, until
        // `php artisan permission:cache-reset` was run by hand. Every
        // future migration that writes this table directly needs the same
        // explicit flush — doing it here rather than relying on a manual
        // step after every deploy.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Intentionally a no-op — see 2026_08_26_060000_... for the reasoning.
    }
};
