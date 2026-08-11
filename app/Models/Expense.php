<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        'name', 'category_id', 'amount', 'tax_rate', 'tax_amount', 'payment_mode',
        'expense_date', 'reference', 'notes', 'created_by',
    ];

    protected $casts = [
        'amount'       => 'integer',
        'tax_rate'     => 'float',
        'tax_amount'   => 'integer',
        'expense_date' => 'date',
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
}
