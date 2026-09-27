<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Manufacturer-supplied supporting documents (ISO 13485 certificates,
// declarations, technical-file excerpts), tagged with the checklist
// principles they support.
class DeviceRegistrationFile extends Model
{
    protected $fillable = ['device_registration_id', 'path', 'original_name', 'principle_nos', 'description', 'uploaded_by'];

    protected $casts = ['principle_nos' => 'array'];
}
