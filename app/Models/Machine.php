<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'serial_no', 'model', 'type', 'hospital_id', 'ward',
        'install_date', 'warranty_expiry', 'status', 'revenue_per_month',
    ];

    protected $casts = [
        'install_date' => 'date',
        'warranty_expiry' => 'date',
        'revenue_per_month' => 'integer',
    ];

    // Maps DB status to Flutter CSS short code
    public static array $statusCodes = [
        'operational'  => 'op',
        'needs_service' => 'svc',
        'down'         => 'down',
        'warranty'     => 'claim',
        'idle'         => 'idle',
    ];

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function tickets()
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
