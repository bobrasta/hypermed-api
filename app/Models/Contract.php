<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contract extends Model
{
    protected $fillable = [
        'user_id', 'contract_type', 'start_date', 'end_date',
        'probation_period_days', 'probation_end_date', 'base_salary',
        'status', 'resignation_date', 'resignation_reason',
        'renewed_from_contract_id', 'expiry_notified_at', 'probation_notified_at',
        'document_path', 'document_name', 'document_uploaded_at',
        'created_by',
    ];

    protected $casts = [
        'start_date'          => 'date',
        'end_date'            => 'date',
        'probation_end_date'  => 'date',
        'resignation_date'    => 'date',
        'expiry_notified_at'  => 'datetime',
        'probation_notified_at' => 'datetime',
        'document_uploaded_at' => 'datetime',
        'base_salary'         => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function renewedFrom()
    {
        return $this->belongsTo(Contract::class, 'renewed_from_contract_id');
    }

    public function renewals()
    {
        return $this->hasMany(Contract::class, 'renewed_from_contract_id');
    }

    public function allowances()
    {
        return $this->hasMany(Allowance::class);
    }
}
