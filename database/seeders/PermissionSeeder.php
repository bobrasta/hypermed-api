<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Phase 1 of the dynamic access-control system: seeds the 12 legacy roles
 * as real (editable, deletable-if-not-system) Spatie roles, backfills every
 * existing user onto them so nothing changes in production the moment this
 * ships, and seeds the module-wide permission catalog including the
 * "bridge" permissions that now back User::hasXAuthority()'s dynamic checks
 * (see app/Models/User.php and app/Services/EffectivePermissionResolver.php).
 *
 * One-time bootstrap, guarded and safe to wire into every deploy: the main
 * step no-ops once any role exists. This is deliberate — after the first
 * run, an admin can freely edit role permissions via the Role Builder, and
 * a later, unrelated deploy must never silently re-assert this seed data
 * over their changes. Later additions (like seedScreenPermissions()) get
 * their own independent guard and get called unconditionally below, so
 * this one seeder class can keep growing without a new deploy-chain entry
 * per addition — each step just needs to check for its own prior existence
 * before writing anything.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        if (Role::count() === 0) {
            $roles = $this->seedRoles();
            $this->backfillUserRoles($roles);
            $permissions = $this->seedPermissionCatalog();
            $this->seedBridgePermissions($roles, $permissions);
            $this->seedModulePermissions($roles, $permissions);
        }

        $this->seedScreenPermissions();
    }

    /** @return array<string, Role> */
    private function seedRoles(): array
    {
        $roles = [];
        foreach (User::ROLES as $name) {
            $roles[$name] = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['is_system' => true]
            );
        }

        return $roles;
    }

    /** @param array<string, Role> $roles */
    private function backfillUserRoles(array $roles): void
    {
        User::whereDoesntHave('roles')->each(function (User $user) use ($roles) {
            if (isset($roles[$user->role])) {
                $user->assignRole($roles[$user->role]);
            }
        });
    }

    /** @return array<string, Permission> */
    private function seedPermissionCatalog(): array
    {
        // module => [key => [label, description]]
        $catalog = [
            'sales' => [
                'sales.create'                 => ['Create Sales Records', 'Create quotations and sales orders'],
                'sales.edit'                   => ['Edit Sales Records', 'Edit existing quotations and sales orders'],
                'sales.issue_quotation'        => ['Issue Quotations', 'Send a quotation to a client'],
                'sales.approve_order'          => ['Approve Sales Orders', 'Approve a quotation/order that exceeds a discount or value threshold'],
                'sales.view_full_numbers'      => ['View Full Sales Figures', 'See exact revenue numbers rather than masked/rounded ones'],
                'sales.create_subordinate_user'=> ['Create Subordinate Sales Users', 'Add sales staff reporting to this user'],
            ],
            'services' => [
                'services.issue_ticket'        => ['Issue Service Tickets', 'Open a new service ticket'],
                'services.add_engineer'        => ['Add Engineers', 'Register a new technician/engineer'],
                'services.assign_ticket'       => ['Assign Service Tickets', 'Assign or reassign a technician to a ticket'],
                'services.close_ticket'        => ['Close Service Tickets', 'Mark a service ticket resolved'],
                'services.view_team_metrics'   => ['View Team Metrics', "See the wider team's ticket/workload stats"],
            ],
            'finance' => [
                'finance.view_revenue'         => ['View Revenue Figures', 'See ledger revenue and profit numbers'],
                'finance.approve_step1'        => ['Approve Finance — Stage 1', 'First-stage finance approval (e.g. period close)'],
                'finance.approve_step2'        => ['Approve Finance — Stage 2', 'Second-stage / final finance approval'],
                'finance.export_reports'       => ['Export Finance Reports', 'Download finance reports as CSV/PDF'],
            ],
            'inventory' => [
                'inventory.adjust_stock'       => ['Adjust Stock Levels', 'Manually adjust on-hand stock quantities'],
                'inventory.view_valuation'     => ['View Inventory Valuation', 'See total stock value figures'],
                'inventory.approve_writeoff'   => ['Approve Stock Write-offs', 'Approve an issue/write-off stock-out request'],
                'inventory.transfer_stock'     => ['Transfer Stock', 'Move stock between locations'],
            ],
            'equipment' => [
                'equipment.register_asset'         => ['Register Equipment', 'Add a new machine/asset record'],
                'equipment.schedule_maintenance'   => ['Schedule Maintenance', 'Schedule preventive maintenance for a machine'],
                'equipment.approve_disposal'       => ['Approve Equipment Disposal', 'Approve retiring/disposing of a machine'],
            ],
            'pos' => [
                'pos.process_sale'             => ['Process POS Sales', 'Ring up a point-of-sale transaction'],
                'pos.issue_refund'             => ['Issue POS Refunds', 'Refund a point-of-sale transaction'],
                'pos.view_shift_totals'        => ['View Shift Totals', "See a cashier shift's sales totals"],
            ],
            'hr' => [
                'hr.approve_leave'             => ['Approve Leave Requests', 'Approve or reject a staff leave request'],
                'hr.view_team_attendance'      => ['View Team Attendance', 'See late-arrival and attendance reports'],
            ],
            // Legacy bridge permissions — see app/Models/User.php's hasXAuthority()
            // methods and the seedBridgePermissions() assignment below. Each
            // bundles multiple real actions from the 3 existing approval flows;
            // decomposing them further is deferred (see plan doc, Phase 2).
            'authority' => [
                'authority.admin_tier'         => ['Full System Authority', 'Director-tier authority — supersedes every module-specific gate'],
                'authority.manager_tier'       => ['Cross-Department Manager Authority', 'Task assignment/reassignment across any department'],
                'authority.cto_tier'           => ['CTO Approval Authority', 'Bundles stock-out approval, per-diem final approval, expense CTO-stage approval, and service-ticket technician assignment'],
                'authority.team_lead_tier'     => ['Team Lead Approval Authority', 'Per-diem team-lead (first) stage approval'],
            ],
            'admin' => [
                'roles.manage'                 => ['Manage Roles & Permissions', 'Create/edit roles and their permission grants'],
            ],
        ];

        $permissions = [];
        foreach ($catalog as $module => $keys) {
            foreach ($keys as $key => [$label, $description]) {
                $permissions[$key] = Permission::firstOrCreate(
                    ['name' => $key, 'guard_name' => 'web'],
                    ['module' => $module, 'label' => $label, 'description' => $description]
                );
            }
        }

        return $permissions;
    }

    /**
     * Exact 1:1 replication of the pre-Phase-1 role-tier constants in
     * User.php — this is what keeps hasXAuthority() call sites behaviorally
     * unchanged across the 15+ controllers (including the 3 live approval
     * workflows) that call them.
     *
     * @param array<string, Role> $roles
     * @param array<string, Permission> $permissions
     */
    private function seedBridgePermissions(array $roles, array $permissions): void
    {
        $grants = [
            'authority.admin_tier'     => ['super_admin', 'admin'],
            'authority.manager_tier'   => ['super_admin', 'admin', 'sales_manager', 'finance_manager'],
            'sales.approve_order'      => ['super_admin', 'admin', 'sales_manager'],
            'finance.approve_step1'    => ['super_admin', 'admin', 'finance_manager'],
            'hr.approve_leave'         => ['super_admin', 'admin', 'hr'],
            'authority.cto_tier'       => ['super_admin', 'admin', 'cto'],
            'authority.team_lead_tier' => ['super_admin', 'admin', 'cto', 'team_leader'],
        ];

        foreach ($grants as $permKey => $roleNames) {
            foreach ($roleNames as $roleName) {
                $this->grantRolePermission($roles[$roleName], $permissions[$permKey]);
            }
        }
    }

    /**
     * Reasonable starting grants for the forward-looking catalog — not
     * consumed by any controller yet in this pass, so mistakes here are
     * cosmetic (Role Builder starting state), not a regression risk.
     *
     * @param array<string, Role> $roles
     * @param array<string, Permission> $permissions
     */
    private function seedModulePermissions(array $roles, array $permissions): void
    {
        $fullAccessKeys = array_keys($permissions);
        foreach (['super_admin', 'admin'] as $tier) {
            foreach ($fullAccessKeys as $key) {
                $this->grantRolePermission($roles[$tier], $permissions[$key]);
            }
        }

        $grants = [
            'sales_manager' => ['sales.create', 'sales.edit', 'sales.issue_quotation', 'sales.view_full_numbers', 'sales.create_subordinate_user'],
            'sales'         => ['sales.create', 'sales.edit', 'sales.issue_quotation'],
            'finance_manager'=> ['finance.view_revenue', 'finance.approve_step2', 'finance.export_reports'],
            'finance'       => [], // scoped grant below — 'masked' revenue view only
            'technician'    => ['services.issue_ticket', 'services.close_ticket', 'equipment.schedule_maintenance'],
            'cs'            => ['services.issue_ticket'],
            'storekeeper'   => ['inventory.adjust_stock', 'inventory.transfer_stock', 'inventory.view_valuation'],
            'hr'            => ['hr.view_team_attendance'],
            'cto'           => ['services.assign_ticket', 'services.add_engineer', 'services.view_team_metrics', 'inventory.approve_writeoff'],
            'team_leader'   => ['services.view_team_metrics'],
        ];

        foreach ($grants as $roleName => $permKeys) {
            foreach ($permKeys as $key) {
                $this->grantRolePermission($roles[$roleName], $permissions[$key]);
            }
        }

        // Real demonstration of the scope column: rank-and-file finance staff
        // see revenue figures at 'masked' scope, their manager at 'all'.
        $this->grantRolePermission($roles['finance'], $permissions['finance.view_revenue'], 'masked');
    }

    // Mirrors Flutter's main.dart allowedScreenKeys() switch exactly, as real
    // grantable permissions — so a new role built via the Role Builder can
    // see relevant screens immediately instead of needing a Flutter redeploy
    // to add a case to that switch. Flutter falls back to the old hardcoded
    // table only if a user has none of these (e.g. permissions fetch failed).
    // Independently guarded (not the top-level Role::count() check) so this
    // step can be added after the initial bootstrap already ran in production.
    private function seedScreenPermissions(): void
    {
        if (Permission::where('name', 'screens.notifications')->exists()) {
            return;
        }

        $screenKeys = [
            'dashboard', 'approvals', 'machines', 'detail', 'hospitals', 'service',
            'inventory', 'finance', 'staff', 'my_leave', 'reports', 'settings',
            'sales', 'customers', 'revenue', 'email', 'hr_approvals', 'notifications',
        ];

        $roles = Role::whereIn('name', User::ROLES)->get()->keyBy('name');

        $screenPermissions = [];
        foreach ($screenKeys as $key) {
            $screenPermissions[$key] = Permission::firstOrCreate(
                ['name' => "screens.{$key}", 'guard_name' => 'web'],
                ['module' => 'screens', 'label' => 'View '.ucwords(str_replace('_', ' ', $key)).' Screen']
            );
        }

        $grants = [
            'super_admin'     => $screenKeys,
            'admin'           => $screenKeys,
            'cto'             => ['dashboard', 'approvals', 'machines', 'detail', 'hospitals', 'service', 'inventory', 'finance', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'technician'      => ['dashboard', 'machines', 'detail', 'hospitals', 'service', 'inventory', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'team_leader'     => ['dashboard', 'approvals', 'machines', 'detail', 'hospitals', 'service', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'sales_manager'   => ['dashboard', 'machines', 'detail', 'sales', 'customers', 'revenue', 'email', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'sales'           => ['dashboard', 'machines', 'detail', 'sales', 'customers', 'revenue', 'email', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'finance_manager' => ['dashboard', 'revenue', 'finance', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'finance'         => ['dashboard', 'revenue', 'finance', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'cs'              => ['dashboard', 'customers', 'service', 'email', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'storekeeper'     => ['dashboard', 'inventory', 'staff', 'my_leave', 'reports', 'settings', 'notifications'],
            'hr'              => ['dashboard', 'my_leave', 'hr_approvals', 'staff', 'reports', 'settings', 'notifications'],
        ];

        foreach ($grants as $roleName => $keys) {
            if (! isset($roles[$roleName])) {
                continue;
            }
            foreach ($keys as $key) {
                $this->grantRolePermission($roles[$roleName], $screenPermissions[$key]);
            }
        }
    }

    private function grantRolePermission(Role $role, Permission $permission, string $scope = 'all'): void
    {
        DB::table('role_has_permissions')->updateOrInsert(
            ['role_id' => $role->id, 'permission_id' => $permission->id],
            ['scope' => $scope]
        );
    }
}
