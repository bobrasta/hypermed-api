<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'staff_group', 'zone',
        'phone', 'region', 'avatar_initials', 'avail_status',
        'workload', 'is_active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_active' => 'boolean',
            'workload'  => 'float',
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
}
