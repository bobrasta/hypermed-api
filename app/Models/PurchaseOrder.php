<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'supplier_id', 'purchase_requisition_id', 'status',
        'ordered_by', 'expected_delivery_date', 'actual_delivery_date',
        'currency', 'total_amount', 'amount_paid', 'payment_status',
        'shipping_address', 'terms', 'notes', 'sent_at',
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'actual_delivery_date'   => 'date',
        'sent_at'                => 'datetime',
        'total_amount'           => 'integer',
        'amount_paid'            => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
