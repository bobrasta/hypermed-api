<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per status move — the shipment timeline (18.5) and the audit of backward corrections. */
class ShipmentEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['shipment_id', 'from_step', 'to_step', 'note', 'reason', 'user_id', 'created_at'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
