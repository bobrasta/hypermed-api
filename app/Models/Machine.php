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
        'sales_order_id', 'installation_ticket_id',
        'installed_by', 'installed_at', 'signed_off_by', 'signed_off_at',
    ];

    protected $casts = [
        'install_date' => 'date',
        'warranty_expiry' => 'date',
        'revenue_per_month' => 'integer',
        'installed_at' => 'datetime',
        'signed_off_at' => 'datetime',
    ];

    // Maps DB status to Flutter CSS short code
    public static array $statusCodes = [
        'pending_installation' => 'pending_install',
        'pending_signoff'      => 'pending_signoff',
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

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function installationTicket()
    {
        return $this->belongsTo(ServiceTicket::class, 'installation_ticket_id');
    }

    public function installedBy()
    {
        return $this->belongsTo(User::class, 'installed_by');
    }

    public function signedOffBy()
    {
        return $this->belongsTo(User::class, 'signed_off_by');
    }
}
