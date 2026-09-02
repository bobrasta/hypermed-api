<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'balance'    => 'integer',
        'created_at' => 'datetime',
    ];

    public function category()
    {
        return $this->belongsTo(AccountCategory::class, 'category_id');
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'account_id');
    }

    public function expenseCategories()
    {
        return $this->hasMany(ExpenseCategory::class, 'account_id');
    }
}
