<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MachineTransfer extends Model
{
    protected $fillable = [
        'machine_id', 'transfer_type', 'from_hospital_id', 'to_hospital_id',
        'from_location_id', 'reason', 'quotation_id', 'invoice_id',
        'approved_by', 'document_path',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function fromHospital()
    {
        return $this->belongsTo(Hospital::class, 'from_hospital_id');
    }

    public function toHospital()
    {
        return $this->belongsTo(Hospital::class, 'to_hospital_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function quotation()
    {
        return $this->belongsTo(Quotation::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
