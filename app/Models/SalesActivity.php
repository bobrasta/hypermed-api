<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesActivity extends Model
{
    protected $fillable = [
        'created_by', 'type', 'sales_lead_id', 'hospital_id', 'hospital_name_raw',
        'subject', 'note', 'occurs_at',
    ];

    protected $casts = ['occurs_at' => 'datetime'];

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lead()
    {
        return $this->belongsTo(SalesLead::class, 'sales_lead_id');
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function getClientNameAttribute(): ?string
    {
        return $this->hospital?->name ?? $this->hospital_name_raw
            ?? $this->lead?->hospital?->name ?? $this->lead?->hospital_name_raw;
    }
}
