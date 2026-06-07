<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name', 'last_name', 'job_title', 'department',
        'email', 'phone', 'whatsapp', 'hospital_id',
        'last_contacted_at', 'next_followup_at',
    ];

    protected $casts = [
        'last_contacted_at' => 'datetime',
        'next_followup_at' => 'datetime',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function tags()
    {
        return $this->hasMany(ContactTag::class);
    }

    public function interactions()
    {
        return $this->hasMany(ContactInteraction::class);
    }
}
