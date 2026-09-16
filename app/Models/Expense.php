<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Expense extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'name', 'category_id', 'amount', 'tax_rate', 'tax_amount', 'payment_mode',
        'expense_date', 'reference', 'notes', 'created_by',
        'status', 'requires_director_approval', 'escalation_reason',
        'escalated_by', 'escalated_at', 'reviewed_by', 'reviewed_at', 'rejection_reason',
        'payment_initiated_by', 'payment_initiated_at', 'payment_method', 'payment_reference',
        'paid_by', 'paid_at',
    ];

    protected $casts = [
        'amount'                     => 'integer',
        'tax_rate'                   => 'float',
        'tax_amount'                 => 'integer',
        'expense_date'               => 'date',
        'requires_director_approval' => 'boolean',
        'escalated_at'               => 'datetime',
        'reviewed_at'                => 'datetime',
        'payment_initiated_at'       => 'datetime',
        'paid_at'                    => 'datetime',
    ];

    // Cash actually paid — amount is the net (pre-VAT) figure.
    public function getGrossAmountAttribute(): int
    {
        return $this->amount + $this->tax_amount;
    }

    public function category()
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function escalator()
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function paymentInitiatedBy()
    {
        return $this->belongsTo(User::class, 'payment_initiated_by');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
