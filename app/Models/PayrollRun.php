<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $fillable = [
        'period_month', 'period_year', 'status',
        'gross_total', 'deductions_total', 'net_total',
        'created_by', 'approved_by', 'approved_at', 'paid_at', 'expense_id',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'paid_at'     => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }
}
