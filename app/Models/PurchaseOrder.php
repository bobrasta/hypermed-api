<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'supplier_id', 'location_id', 'purchase_requisition_id', 'status',
        'ordered_by', 'expected_delivery_date', 'actual_delivery_date',
        'currency', 'total_amount', 'amount_paid', 'payment_status',
        'shipping_address', 'terms', 'notes', 'sent_at',
        'sales_approved_by', 'sales_approved_at',
        'director_reviewed_by', 'director_reviewed_at',
        'payment_initiated_by', 'payment_initiated_at',
        'director_approved_by', 'director_approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'expected_delivery_date' => 'date',
        'actual_delivery_date'   => 'date',
        'sent_at'                => 'datetime',
        'total_amount'           => 'integer',
        'amount_paid'            => 'integer',
        'sales_approved_at'      => 'datetime',
        'director_reviewed_at'   => 'datetime',
        'payment_initiated_at'   => 'datetime',
        'director_approved_at'   => 'datetime',
        'rejected_at'            => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function requisition()
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function orderedBy()
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function salesApprovedBy()
    {
        return $this->belongsTo(User::class, 'sales_approved_by');
    }

    public function directorReviewedBy()
    {
        return $this->belongsTo(User::class, 'director_reviewed_by');
    }

    public function paymentInitiatedBy()
    {
        return $this->belongsTo(User::class, 'payment_initiated_by');
    }

    public function directorApprovedBy()
    {
        return $this->belongsTo(User::class, 'director_approved_by');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}
