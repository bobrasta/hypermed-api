<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerDiemRequest extends Model
{
    protected $fillable = [
        'user_id', 'service_ticket_id', 'destination', 'start_date', 'end_date',
        'days_count', 'daily_rate', 'amount', 'purpose', 'status',
        'team_lead_reviewed_by', 'team_lead_reviewed_at', 'team_lead_rejection_reason',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected $casts = [
        'start_date'             => 'date',
        'end_date'               => 'date',
        'team_lead_reviewed_at'  => 'datetime',
        'reviewed_at'            => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function serviceTicket()
    {
        return $this->belongsTo(ServiceTicket::class);
    }

    public function teamLeadReviewer()
    {
        return $this->belongsTo(User::class, 'team_lead_reviewed_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines()
    {
        return $this->hasMany(PerDiemLine::class)->orderBy('seq_no');
    }
}
