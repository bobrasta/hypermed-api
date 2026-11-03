<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Letter extends Model
{
    protected $fillable = [
        'reference_number', 'letter_date', 'recipient', 'subject', 'body', 'signatory_id',
        'signatory_position', 'signed_at', 'include_stamp', 'created_by',
    ];

    protected $casts = [
        'letter_date'   => 'date',
        'signed_at'     => 'datetime',
        'include_stamp' => 'boolean',
    ];

    public function signatory()
    {
        return $this->belongsTo(User::class, 'signatory_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
