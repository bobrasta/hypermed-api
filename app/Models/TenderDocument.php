<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenderDocument extends Model
{
    /** Generated from templates; 'contract' comes from the buyer and is upload-only. */
    public const GENERATED = ['power_of_attorney', 'bid_securing_declaration', 'performance_securing_declaration', 'acceptance_letter'];
    public const TYPES = [...self::GENERATED, 'contract'];

    public const LABELS = [
        'power_of_attorney' => 'Power of Attorney',
        'bid_securing_declaration' => 'Bid Securing Declaration',
        'performance_securing_declaration' => 'Performance Securing Declaration',
        'acceptance_letter' => 'Acceptance Letter',
        'contract' => 'Contract',
    ];

    protected $fillable = [
        'tender_id', 'type', 'draft_path', 'draft_generated_at', 'draft_generated_by',
        'executed_path', 'executed_original_name', 'executed_uploaded_at', 'executed_uploaded_by',
    ];

    protected $casts = ['draft_generated_at' => 'datetime', 'executed_uploaded_at' => 'datetime'];

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }
}
