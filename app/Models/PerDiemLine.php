<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PerDiemLine extends Model
{
    protected $fillable = [
        'per_diem_request_id', 'seq_no', 'date', 'region', 'district',
        'site_name', 'activity', 'labor_cost', 'per_diem_cost', 'transport_fare',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function perDiemRequest()
    {
        return $this->belongsTo(PerDiemRequest::class);
    }
}
