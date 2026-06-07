<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hospital extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_code', 'type', 'region', 'district',
        'latitude', 'longitude', 'zone',
        'machine_count', 'machines_operational', 'revenue_monthly',
        'contact_name', 'contact_phone', 'contact_email', 'notes',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'revenue_monthly' => 'integer',
    ];

    public function machines()
    {
        return $this->hasMany(Machine::class);
    }

    public function tickets()
    {
        return $this->hasMany(ServiceTicket::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function leads()
    {
        return $this->hasMany(SalesLead::class);
    }

    public function contacts()
    {
        return $this->hasMany(Contact::class);
    }
}
