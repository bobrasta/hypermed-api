<?php

namespace App\Models;

use App\Services\EffectivePermissionResolver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    // Single source of truth for the role list — mirrors the users_role_check
    // constraint AND the seeded Spatie roles (see PermissionSeeder). Keep in
    // sync when adding a role.
    public const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
        'cto', 'team_leader', 'procurement_manager', 'accountant', 'logistics',
    ];

    // These tier lists are NOT used for the hasXAuthority() boolean checks
    // below anymore (those now go through EffectivePermissionResolver) — they
    // still back several controllers' `User::whereIn('role', User::CTO_TIER)`
    // notification-recipient queries (StockOutRequestController, PerDiemController,
    // ExpenseController, LeaveController, LateArrivalController, TaskController).
    // Kept as-is deliberately: rewiring notification fan-out to query by
    // Spatie permission instead of role string is real, separate work,
    // out of scope for this pass. Known limitation: if the Role Builder is
    // used to grant e.g. authority.cto_tier to a role NOT in CTO_TIER, that
    // role's users will be able to approve but won't be notified — flag this
    // to whoever uses the Role Builder until the notification queries are
    // migrated too.
    public const ADMIN_TIER = ['super_admin', 'admin'];
    public const MANAGER_ROLES = ['super_admin', 'admin', 'sales_manager', 'finance_manager'];
    public const SALES_APPROVAL_ROLES = ['super_admin', 'admin', 'sales_manager'];
    public const FINANCE_APPROVAL_ROLES = ['super_admin', 'admin', 'finance_manager'];
    public const HR_APPROVAL_ROLES = ['super_admin', 'admin', 'hr'];
    public const CTO_TIER = ['super_admin', 'admin', 'cto'];
    public const TEAM_LEAD_APPROVAL_ROLES = [...self::CTO_TIER, 'team_leader'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'staff_group', 'zone',
        'phone', 'region', 'avatar_initials', 'avail_status',
        'workload', 'is_active', 'max_discount_percent', 'commission_percent',
        'manager_id', 'position_id', 'gender', 'hire_date',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
        'nssf_number', 'tin_number', 'nida_number', 'biometric_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
            'workload'  => 'float',
            'max_discount_percent' => 'float',
            'commission_percent'   => 'float',
            'hire_date' => 'date',
        ];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function directReports()
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function position()
    {
        return $this->belongsTo(Position::class);
    }

    public function tickets()
    {
        return $this->hasMany(ServiceTicket::class, 'assigned_to');
    }

    public function currentTask()
    {
        return $this->hasOne(ServiceTicket::class, 'assigned_to')
            ->whereIn('status', ['open', 'in_progress'])
            ->latestOfMany();
    }

    public function leads()
    {
        return $this->hasMany(SalesLead::class, 'assigned_to');
    }

    public function appNotifications()
    {
        return $this->hasMany(AppNotification::class);
    }

    // Each of these now delegates to the permission resolver instead of a
    // hardcoded role array — the *behavior* they gate is unchanged (same
    // call sites, same roles hold them today, per PermissionSeeder's bridge
    // permissions), but which roles hold them is now admin-editable data,
    // not a code deploy. See the "authority.*" bridge permissions for why
    // cto_tier/team_lead_tier/manager_tier/admin_tier aren't decomposed into
    // fully atomic permissions yet — they each currently gate multiple
    // distinct actions bundled together in the 3 existing approval flows.
    public function isAdminTier(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'authority.admin_tier');
    }

    public function isManagerTier(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'authority.manager_tier');
    }

    public function hasSalesApprovalAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'sales.approve_order');
    }

    public function hasFinanceApprovalAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'finance.approve_step1');
    }

    public function hasHrAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'hr.approve_leave');
    }

    public function hasCtoApprovalAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'authority.cto_tier');
    }

    public function hasTeamLeadAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'authority.team_lead_tier');
    }

    public function hasProcurementApprovalAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'procurement.approve_requisition');
    }

    public function hasStaffManageAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'staff.manage');
    }

    // Ticket creation had no gate at all — reachable from the Dashboard's
    // "New Ticket" shortcut by literally any role, finance/accountant
    // included, since that button isn't behind the dedicated Service
    // screen. Reuses screens.service rather than a new permission key:
    // whoever can see the Service screen is exactly who should be able to
    // open a ticket from anywhere else in the app too.
    public function hasServiceTicketCreateAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'screens.service');
    }

    public function hasProcurementCreateAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'procurement.create_po');
    }

    public function hasProcurementSalesStageAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'procurement.approve_po_sales_stage');
    }

    public function hasAccountantAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'procurement.initiate_payment');
    }

    // Deliberately two separate checks, not one — the logistics role holds
    // both permissions, but storekeeper only holds receive_order (they
    // already receive shipments today with no gate at all; this preserves
    // that without also letting them mark customer orders delivered).
    public function hasLogisticsDeliverAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'logistics.deliver_order');
    }

    public function hasLogisticsReceiveAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'logistics.receive_order');
    }

    public function hasEquipmentSignOffAuthority(): bool
    {
        return app(EffectivePermissionResolver::class)->can($this, 'services.sign_off_installation');
    }

    // "Director" is a semantic alias for the existing admin tier, not a new
    // role or membership list — keeps intent readable at approval call sites
    // without a second list that can drift from authority.admin_tier. Also
    // passes for a non-admin-tier user holding an active Delegation, so
    // every existing director-gated call site (Expense, PurchaseOrder, ...)
    // honours a delegation automatically without being touched.
    public function hasDirectorAuthority(): bool
    {
        return $this->isAdminTier() || $this->activeDelegationAsDelegate() !== null;
    }

    // The delegation, if any, currently letting this user act with Director
    // authority despite not being admin-tier themselves. Also used to stamp
    // approval-log rows with which delegation an approval was made under.
    public function activeDelegationAsDelegate(): ?Delegation
    {
        return Delegation::query()->active()->where('delegate_id', $this->id)->latest('starts_at')->first();
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function contracts()
    {
        return $this->hasMany(Contract::class);
    }

    public function activeContract()
    {
        return $this->hasOne(Contract::class)->where('status', 'active')->latestOfMany('start_date');
    }

    public function disciplinaryCases()
    {
        return $this->hasMany(DisciplinaryCase::class);
    }

    public function salaryAdjustments()
    {
        return $this->hasMany(SalaryAdjustment::class);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function positionChanges()
    {
        return $this->hasMany(PositionChange::class);
    }
}
