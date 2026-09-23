<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Vendor extends Model
{
    use LogsActivity;

    protected $fillable = [
        'name', 'type', 'tin', 'payment_account_type', 'payment_account_name',
        'payment_account_number', 'payment_bank_name', 'contact_name',
        'contact_phone', 'contact_email', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function staff()
    {
        return $this->hasMany(User::class);
    }

    public function fees()
    {
        return $this->hasMany(VendorFee::class);
    }

    public function deliveryJobs()
    {
        return $this->hasMany(DeliveryJob::class);
    }
}
