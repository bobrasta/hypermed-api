<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentSequence extends Model
{
    protected $fillable = ['document_type', 'label', 'prefix', 'digits', 'reset_yearly', 'next_number', 'year'];

    protected $casts = [
        'digits'       => 'integer',
        'reset_yearly' => 'boolean',
        'next_number'  => 'integer',
        'year'         => 'integer',
    ];
}
