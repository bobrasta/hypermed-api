<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A tender published on NeST (nest.go.tz), kept in sync by `nest:sync`.
 * Triage (new / reviewing / skipped) happens here; "Start bid" links a
 * regular Section 19 Tender, whose status is the pipeline from then on.
 */
class NestTender extends Model
{
    public const TRIAGE = ['new', 'reviewing', 'skipped'];

    /** Pipeline buckets shown in the app, derived from triage + linked tender. */
    public const PIPELINE = ['new', 'reviewing', 'bidding', 'submitted', 'won', 'lost', 'skipped'];

    // Linked tender statuses per pipeline bucket.
    public const BIDDING = ['identified', 'preparing_bid'];
    public const SUBMITTED = ['bid_submitted', 'awaiting_award'];
    public const WON = ['won', 'accepted', 'performance_security_submitted', 'contract_signed', 'fulfilling', 'delivered', 'closed'];
    public const LOST = ['lost', 'cancelled'];

    protected $fillable = [
        'entity_uuid', 'entity_id', 'entity_number', 'reference_number', 'entity_type', 'entity_status',
        'description', 'sub_category_acronym', 'sub_category_name', 'procurement_category',
        'procurement_category_name', 'procuring_entity_name', 'procuring_entity_uuid', 'financial_year',
        'lot_count', 'has_addendum', 'eligible_types', 'invitation_at', 'closing_at', 'relevance_score',
        'is_relevant', 'tags', 'matched_keywords', 'triage_status', 'notes', 'assigned_to', 'tender_id',
        'notified_at', 'first_seen_at', 'last_seen_at', 'raw',
    ];

    protected $casts = [
        'has_addendum' => 'boolean',
        'is_relevant' => 'boolean',
        'tags' => 'array',
        'matched_keywords' => 'array',
        'raw' => 'array',
        'invitation_at' => 'datetime',
        'closing_at' => 'datetime',
        'notified_at' => 'datetime',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function changes()
    {
        return $this->hasMany(NestTenderChange::class)->latest('detected_at');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function pipelineStatus(): string
    {
        $status = $this->tender?->status;
        if ($status === null) {
            return $this->triage_status;
        }

        return match (true) {
            in_array($status, self::SUBMITTED, true) => 'submitted',
            in_array($status, self::WON, true) => 'won',
            in_array($status, self::LOST, true) => 'lost',
            default => 'bidding',
        };
    }

    /** Being worked on: a change to it is worth a notification. */
    public function isTracked(): bool
    {
        return in_array($this->pipelineStatus(), ['reviewing', 'bidding', 'submitted'], true);
    }

    public function nestUrl(): string
    {
        return config('nest.web_url');
    }

    // ------------------------------------------------------------- scopes

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('closing_at', '>', now());
    }

    public function scopeRelevant(Builder $q): Builder
    {
        return $q->where('is_relevant', true);
    }

    public function scopeMaybe(Builder $q): Builder
    {
        return $q->where('is_relevant', false)->where('relevance_score', '>=', (int) config('nest.maybe_min'));
    }

    public function scopeClosingSoon(Builder $q, ?int $days = null): Builder
    {
        $days ??= (int) config('nest.closing_soon_days');

        return $q->open()->where('closing_at', '<=', now()->addDays($days));
    }

    /** @param list<string> $buckets */
    public function scopePipeline(Builder $q, array $buckets): Builder
    {
        return $q->where(function (Builder $w) use ($buckets) {
            foreach ($buckets as $b) {
                match ($b) {
                    'new', 'reviewing', 'skipped' => $w->orWhere(fn ($x) => $x->whereNull('tender_id')->where('triage_status', $b)),
                    'bidding' => $w->orWhereHas('tender', fn ($t) => $t->whereNotIn('status', [...self::SUBMITTED, ...self::WON, ...self::LOST])),
                    'submitted' => $w->orWhereHas('tender', fn ($t) => $t->whereIn('status', self::SUBMITTED)),
                    'won' => $w->orWhereHas('tender', fn ($t) => $t->whereIn('status', self::WON)),
                    'lost' => $w->orWhereHas('tender', fn ($t) => $t->whereIn('status', self::LOST)),
                    default => null,
                };
            }
        });
    }
}
