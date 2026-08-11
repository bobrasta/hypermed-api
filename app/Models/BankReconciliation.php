<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliation extends Model
{
    protected $fillable = [
        'period_from', 'period_to', 'currency', 'statement_closing_balance',
        'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
        'statement_closing_balance' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(BankStatementLine::class, 'reconciliation_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
