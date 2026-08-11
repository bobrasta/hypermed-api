<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'amount'     => 'integer',
        'created_at' => 'datetime',
    ];

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
