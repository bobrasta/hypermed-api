<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Allowance extends Model
{
    protected $fillable = ['contract_id', 'type', 'amount', 'recurring', 'effective_date'];

    protected $casts = [
        'amount'         => 'integer',
        'recurring'      => 'boolean',
        'effective_date' => 'date',
    ];

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }
}
