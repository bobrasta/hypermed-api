<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'hospital_id', 'hospital_name_raw', 'contact_id', 'contact_name_raw',
        'machine_type', 'deal_value', 'stage', 'demo_date', 'assigned_to',
    ];

    protected $casts = [
        'demo_date' => 'date',
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

    public function getDaysInStageAttribute(): int
    {
        return (int) $this->updated_at->diffInDays(now());
    }
}
