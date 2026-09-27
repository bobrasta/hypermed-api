<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Tender extends Model
{
    use LogsActivity;

    /** Main flow, in order. lost/cancelled are terminal side exits. */
    public const FLOW = [
        'identified', 'preparing_bid', 'bid_submitted', 'awaiting_award', 'won', 'accepted',
        'performance_security_submitted', 'contract_signed', 'fulfilling', 'delivered', 'closed',
    ];
    public const TERMINAL = ['lost', 'cancelled', 'closed'];
    public const STATUSES = [...self::FLOW, 'lost', 'cancelled'];

    protected $fillable = [
        'tender_number', 'contract_number', 'tender_type', 'title', 'procuring_entity_id',
        'estimated_value', 'contract_value', 'currency', 'vat_inclusive', 'status', 'owner_id',
        'board_resolution_id', 'attorney_name', 'attorney_address', 'signatory_name', 'signatory_position',
        'entity_ref', 'entity_ref_date', 'our_ref', 'bid_submission_deadline', 'bid_validity_days',
        'tender_expiry_date', 'other_tenderer_notified_at', 'award_notified_at', 'letter_of_acceptance_date',
        'performance_security_form', 'contract_signing_deadline', 'delivery_deadline', 'notes', 'created_by',
    ];

    protected $casts = [
        'vat_inclusive' => 'boolean',
        'estimated_value' => 'integer',
        'contract_value' => 'integer',
        'entity_ref_date' => 'date',
        'bid_submission_deadline' => 'date',
        'tender_expiry_date' => 'date',
        'other_tenderer_notified_at' => 'date',
        'award_notified_at' => 'date',
        'letter_of_acceptance_date' => 'date',
        'contract_signing_deadline' => 'date',
        'delivery_deadline' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function procuringEntity()
    {
        return $this->belongsTo(ProcuringEntity::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function boardResolution()
    {
        return $this->belongsTo(BoardResolution::class);
    }

    public function documents()
    {
        return $this->hasMany(TenderDocument::class);
    }

    /** 1-based position on the main flow; lost/cancelled sit at "Won / Lost". */
    public function step(): int
    {
        return match ($this->status) {
            'lost', 'cancelled' => 5,
            default => array_search($this->status, self::FLOW, true) + 1,
        };
    }

    public function reached(string $status): bool
    {
        return ! in_array($this->status, ['lost', 'cancelled'], true)
            && $this->step() >= array_search($status, self::FLOW, true) + 1;
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, self::TERMINAL, true);
    }

    public function hasExecuted(string $type): bool
    {
        return $this->documents->contains(fn ($d) => $d->type === $type && $d->executed_path);
    }
}
