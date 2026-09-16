<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $fillable = ['name', 'rate', 'is_default', 'is_active'];

    protected $casts = [
        'rate'       => 'float',
        'is_default' => 'boolean',
        'is_active'  => 'boolean',
    ];
}
