<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    // Single source of truth for the role list — mirrors the users_role_check
    // constraint. Keep both in sync when adding a role.
    public const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
    ];

    // System-wide full authority — supersedes every module-specific gate below.
    public const ADMIN_TIER = ['super_admin', 'admin'];

    // Cross-staff supervisory authority: task assignment/reassignment across
    // any department, not just their own.
    public const MANAGER_ROLES = ['super_admin', 'admin', 'sales_manager', 'finance_manager'];

    // Sales-specific approval authority (quotation/sales-order discount approval).
    public const SALES_APPROVAL_ROLES = ['super_admin', 'admin', 'sales_manager'];

    // Finance-specific approval authority (period close, etc).
    public const FINANCE_APPROVAL_ROLES = ['super_admin', 'admin', 'finance_manager'];

    // Leave/attendance approval authority.
    public const HR_APPROVAL_ROLES = ['super_admin', 'admin', 'hr'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'staff_group', 'zone',
        'phone', 'region', 'avatar_initials', 'avail_status',
        'workload', 'is_active', 'max_discount_percent', 'commission_percent',
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
        ];
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

    public function isAdminTier(): bool
    {
        return in_array($this->role, self::ADMIN_TIER, true);
    }

    public function isManagerTier(): bool
    {
        return in_array($this->role, self::MANAGER_ROLES, true);
    }

    public function hasSalesApprovalAuthority(): bool
    {
        return in_array($this->role, self::SALES_APPROVAL_ROLES, true);
    }

    public function hasFinanceApprovalAuthority(): bool
    {
        return in_array($this->role, self::FINANCE_APPROVAL_ROLES, true);
    }

    public function hasHrAuthority(): bool
    {
        return in_array($this->role, self::HR_APPROVAL_ROLES, true);
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }
}
