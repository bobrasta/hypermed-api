<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerDiemEditGrant extends Model
{
    protected $fillable = [
        'per_diem_request_id', 'technician_id', 'granted_by', 'scope',
        'expires_at', 'revoked_by', 'revoked_at',
    ];

    protected $casts = [
        'scope'      => 'array',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function perDiemRequest()
    {
        return $this->belongsTo(PerDiemRequest::class);
    }

    public function technician()
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function grantedBy()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
