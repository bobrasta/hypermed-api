<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeliveryJob extends Model
{
    protected $fillable = [
        'job_number', 'vendor_id', 'invoice_id', 'origin_location_id',
        'destination_hospital_id', 'destination_name', 'destination_address',
        'goods_list', 'status',
        'delivery_note_original_name', 'delivery_note_stored_name', 'delivery_note_mime',
        'delivery_note_size', 'delivery_note_receiver_name', 'delivery_note_date',
        'delivery_note_goods_mismatch', 'delivery_note_uploaded_by', 'delivery_note_uploaded_at',
        'created_by',
    ];

    protected $casts = [
        'goods_list' => 'array',
        'delivery_note_date' => 'date',
        'delivery_note_uploaded_at' => 'datetime',
        'delivery_note_goods_mismatch' => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function originLocation()
    {
        return $this->belongsTo(Location::class, 'origin_location_id');
    }

    public function destinationHospital()
    {
        return $this->belongsTo(Hospital::class, 'destination_hospital_id');
    }

    public function fee()
    {
        return $this->hasOne(VendorFee::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliveryNoteUploadedBy()
    {
        return $this->belongsTo(User::class, 'delivery_note_uploaded_by');
    }

    public function hasDeliveryNote(): bool
    {
        return ! is_null($this->delivery_note_stored_name);
    }
}
