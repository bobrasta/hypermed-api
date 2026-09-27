<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceRequirement extends Model
{
    protected $fillable = ['device_registration_id', 'principle_no', 'principle', 'applicable', 'method', 'supporting_document'];

    protected $casts = ['applicable' => 'boolean'];

    public function isComplete(): bool
    {
        return $this->applicable === false || ($this->applicable === true && filled($this->method) && filled($this->supporting_document));
    }
}
