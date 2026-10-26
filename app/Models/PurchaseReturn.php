<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Goods sent back to the supplier out of a received purchase (purchase order). */
class PurchaseReturn extends Model
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
        'return_number', 'purchase_order_id', 'supplier_id', 'location_id', 'return_date',
        'currency', 'total_amount', 'posted', 'exchange_rate', 'total_tzs', 'reason', 'created_by',
    ];

    protected $casts = [
        'return_date'   => 'date',
        'total_amount'  => 'integer',
        'posted'        => 'boolean',
        'exchange_rate' => 'decimal:6',
        'total_tzs'     => 'integer',
    ];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function location() { return $this->belongsTo(Location::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }
    public function items() { return $this->hasMany(PurchaseReturnItem::class); }
}
