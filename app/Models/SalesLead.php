<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'hospital_id', 'hospital_name_raw', 'contact_id', 'contact_name_raw',
        'source', 'source_notes', 'notes',
        'machine_type', 'deal_value', 'stage', 'demo_date', 'follow_up_date', 'assigned_to',
        'expected_close_date', 'forecast_category',
    ];

    protected $casts = [
        'demo_date' => 'date',
        'follow_up_date' => 'date',
        'expected_close_date' => 'date',
        'deal_value' => 'integer',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events()
    {
        return $this->hasMany(SalesLeadEvent::class)->latest();
    }

    public function getDaysInStageAttribute(): int
    {
        return (int) $this->updated_at->diffInDays(now());
    }
}
