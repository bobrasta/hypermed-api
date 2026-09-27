<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Section 18.3: a shipment's relevant department — its manager is notified. */
class Department extends Model
{
    protected $fillable = ['name', 'manager_id'];

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
