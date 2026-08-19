<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * One-time demo/test accounts for trying out the dynamic access-control
 * system end-to-end: one login per built-in role, a brand-new custom role
 * that has NO entry in Flutter's legacy allowedScreenKeys() switch (proving
 * screen access really is permission-driven now, not hardcoded per role),
 * and two accounts demonstrating individual permission overrides — one
 * allow, one deny. All share the password 'Hypermed@123' — the same
 * default StaffController::store() already uses for new staff.
 *
 * Guarded like PermissionSeeder — no-ops once the first test account
 * exists, so it's safe to leave in the deploy chain permanently. These are
 * clearly test.*@hypermed.tz accounts; delete them from the Staff screen
 * once you're done testing if you don't want them lingering in production.
 */
class TestAccessControlSeeder extends Seeder
{
    private const PASSWORD = 'Hypermed@123';

    public function run(): void
    {
        if (User::where('email', 'test.cto@hypermed.tz')->exists()) {
            return;
        }

        $this->seedOneAccountPerRole();
        $this->seedCustomRoleAccount();
        $this->seedOverrideDemoAccounts();
    }

    private function seedOneAccountPerRole(): void
    {
        $roles = [
            'cto'             => 'Test CTO',
            'team_leader'     => 'Test Team Leader',
            'sales_manager'   => 'Test Sales Manager',
            'sales'           => 'Test Sales Rep',
            'finance_manager' => 'Test Finance Manager',
            'finance'         => 'Test Accountant',
            'technician'      => 'Test Technician',
            'cs'              => 'Test Customer Service',
            'storekeeper'     => 'Test Storekeeper',
            'hr'              => 'Test HR',
        ];

        foreach ($roles as $roleName => $displayName) {
            $user = $this->createUser("test.{$roleName}@hypermed.tz", $displayName, $roleName);
            $user->syncRoles([$roleName]);
        }
    }

    private function seedCustomRoleAccount(): void
    {
        // A genuinely new role with no case in Flutter's legacy
        // allowedScreenKeys() switch — this is the real test of whether
        // screen access is actually dynamic now.
        $role = Role::firstOrCreate(
            ['name' => 'regional_sales_lead', 'guard_name' => 'web'],
            ['is_system' => false]
        );

        $permissionKeys = [
            'sales.approve_order', 'sales.view_full_numbers', 'sales.create_subordinate_user',
            'screens.dashboard', 'screens.sales', 'screens.customers', 'screens.revenue',
            'screens.staff', 'screens.my_leave', 'screens.reports', 'screens.settings',
        ];
        foreach ($permissionKeys as $key) {
            $permission = Permission::where('name', $key)->first();
            if ($permission) {
                DB::table('role_has_permissions')->updateOrInsert(
                    ['role_id' => $role->id, 'permission_id' => $permission->id],
                    ['scope' => 'all']
                );
            }
        }

        // The `role` column stays a valid legacy placeholder (it's still
        // CHECK-constrained to the 12 built-in values) — the account's real
        // effective role/permissions come from the Spatie assignment below,
        // which is what EffectivePermissionResolver and Flutter's screens.*
        // check actually read.
        $user = $this->createUser('test.regionalsales@hypermed.tz', 'Test Regional Sales Lead', 'sales');
        $user->syncRoles(['regional_sales_lead']);
    }

    private function seedOverrideDemoAccounts(): void
    {
        $allowUser = $this->createUser('test.allowoverride@hypermed.tz', 'Test Allow-Override Technician', 'technician');
        $allowUser->syncRoles(['technician']);
        $salesApprove = Permission::where('name', 'sales.approve_order')->first();
        if ($salesApprove) {
            DB::table('user_permission_overrides')->insert([
                'user_id'       => $allowUser->id,
                'permission_id' => $salesApprove->id,
                'effect'        => 'allow',
                'scope'         => 'own',
                'reason'        => 'Demo account — individual grant beyond the technician role',
                'created_at'    => now(),
            ]);
        }

        $denyUser = $this->createUser('test.denyoverride@hypermed.tz', 'Test Deny-Override CS', 'cs');
        $denyUser->syncRoles(['cs']);
        $issueTicket = Permission::where('name', 'services.issue_ticket')->first();
        if ($issueTicket) {
            DB::table('user_permission_overrides')->insert([
                'user_id'       => $denyUser->id,
                'permission_id' => $issueTicket->id,
                'effect'        => 'deny',
                'reason'        => 'Demo account — deny beats the role grant',
                'created_at'    => now(),
            ]);
        }
    }

    private function createUser(string $email, string $name, string $role): User
    {
        return User::create([
            'name'             => $name,
            'email'            => $email,
            'password'         => Hash::make(self::PASSWORD),
            'role'             => $role,
            'avail_status'     => 'Available',
            'is_active'        => true,
            'avatar_initials'  => collect(explode(' ', trim($name)))
                ->map(fn ($p) => strtoupper($p[0] ?? ''))->implode(''),
        ]);
    }
}
