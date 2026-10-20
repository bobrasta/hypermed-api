<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** What Hypermed owed a creditor (or had paid in advance, negative) at the cut-over date. */
class CreditorOpeningBalance extends Model
{
    use LogsActivity;

    protected $fillable = [
        'creditor_type', 'creditor_id', 'as_at', 'currency', 'amount', 'exchange_rate', 'amount_tzs', 'notes', 'created_by',
    ];

    protected $casts = [
        'as_at' => 'date',
        'amount' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'amount_tzs' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
}
