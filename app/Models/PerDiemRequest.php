<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PerDiemRequest extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->fillable)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected $fillable = [
        'user_id', 'staff_name_snapshot', 'staff_designation_snapshot', 'payment_snapshot',
        'service_ticket_id', 'destination', 'start_date', 'end_date',
        'days_count', 'daily_rate', 'amount', 'purpose', 'status',
        'team_lead_reviewed_by', 'team_lead_reviewed_at', 'team_lead_rejection_reason',
        'reviewed_by', 'reviewed_at', 'rejection_reason',
        'payment_initiated_by', 'payment_initiated_at', 'payment_method', 'payment_reference',
        'paid_by', 'paid_at',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'start_date'             => 'date',
        'end_date'               => 'date',
        'team_lead_reviewed_at'  => 'datetime',
        'reviewed_at'            => 'datetime',
        'payment_initiated_at'   => 'datetime',
        'paid_at'                => 'datetime',
        'cancelled_at'           => 'datetime',
        'payment_snapshot'       => 'array',
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

    public function paymentInitiatedBy()
    {
        return $this->belongsTo(User::class, 'payment_initiated_by');
    }

    public function paidBy()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines()
    {
        return $this->hasMany(PerDiemLine::class)->orderBy('seq_no');
    }

    // Section 8: revision history, technician edit grants and post-payment
    // adjustments.
    public function revisions()
    {
        return $this->hasMany(PerDiemRevision::class)->latest();
    }

    public function editGrants()
    {
        return $this->hasMany(PerDiemEditGrant::class)->latest();
    }

    public function adjustments()
    {
        return $this->hasMany(PerDiemAdjustment::class)->latest();
    }

    // "A technician cannot edit by default" — true unless an active,
    // unexpired, unrevoked grant exists.
    public function hasActiveEditGrant(): bool
    {
        return $this->editGrants()->get()->contains(fn (PerDiemEditGrant $g) => $g->isActive());
    }

    // Section 15.3: computed server-side so web, Flutter, PDF and XLSX
    // always agree — the template left these blank/hand-typed, the app
    // calculates all of them. `grand_total` intentionally recomputed from
    // the lines rather than trusting `amount`, though they're kept equal
    // by store()/applyLineEdit() — this is the one place that must never
    // drift even if that invariant is ever broken elsewhere.
    public function summary(): array
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $totalLabor = (int) $lines->sum('labor_cost');
        $totalPerDiem = (int) $lines->sum('per_diem_cost');
        $totalTransport = (int) $lines->sum('transport_fare');
        $grandTotal = $totalLabor + $totalPerDiem + $totalTransport;

        $daysSpent = $lines->pluck('date')->filter()->map(
            fn ($d) => $d instanceof \Carbon\CarbonInterface ? $d->toDateString() : (string) $d
        )->unique()->count();

        $sitesVisited = $lines->pluck('site_name')->filter(fn ($s) => filled($s))->unique()->count();

        return [
            'total_labor' => $totalLabor,
            'total_per_diem' => $totalPerDiem,
            'total_transport' => $totalTransport,
            'grand_total' => $grandTotal,
            'days_spent' => $daysSpent,
            'sites_visited' => $sitesVisited,
            // Guard div-by-zero: a plan with no sites yet (or every line's
            // site left blank) shows "-" in the UI/export rather than
            // dividing by zero — null is the signal for that.
            'avg_days_per_site' => $sitesVisited > 0 ? round($daysSpent / $sitesVisited, 1) : null,
            'avg_cost_per_site' => $sitesVisited > 0 ? round($grandTotal / $sitesVisited) : null,
        ];
    }
}
