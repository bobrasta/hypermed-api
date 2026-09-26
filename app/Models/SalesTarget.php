<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesTarget extends Model
{
    protected $fillable = ['user_id', 'year', 'month', 'amount', 'set_by'];

    protected $casts = ['amount' => 'integer', 'year' => 'integer', 'month' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
