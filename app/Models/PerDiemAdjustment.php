<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerDiemAdjustment extends Model
{
    protected $fillable = [
        'per_diem_request_id', 'per_diem_revision_id', 'amount', 'reason', 'created_by',
    ];

    public function perDiemRequest()
    {
        return $this->belongsTo(PerDiemRequest::class);
    }

    public function revision()
    {
        return $this->belongsTo(PerDiemRevision::class, 'per_diem_revision_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
