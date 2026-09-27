<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Section 18: an import or export shipment. `step` is a 1-based index into
 * the direction's flow (App\Services\Shipment\ShipmentFlow); its clearing
 * cost is the linked Section 16 VendorFee.
 */
class Shipment extends Model
{
    use LogsActivity;

    protected $fillable = [
        'reference', 'direction', 'freight_mode', 'description', 'supplier_id', 'purchase_order_id',
        'outbound_reason', 'department_id', 'tender_id', 'expected_arrival', 'port', 'location_notes',
        'step', 'tmda_application_ref', 'tmda_applied_at', 'tmda_issued_at', 'control_number',
        'vendor_fee_id', 'created_by',
    ];

    protected $casts = [
        'step' => 'integer',
        'expected_arrival' => 'date',
        'tmda_applied_at' => 'date',
        'tmda_issued_at' => 'date',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly($this->fillable)->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function tender()
    {
        return $this->belongsTo(Tender::class);
    }

    public function vendorFee()
    {
        return $this->belongsTo(VendorFee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents()
    {
        return $this->hasMany(ShipmentDocument::class);
    }

    public function events()
    {
        return $this->hasMany(ShipmentEvent::class)->orderBy('id');
    }

    public function machines()
    {
        return $this->belongsToMany(Machine::class);
    }
}
