<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NestTenderChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['nest_tender_id', 'field', 'old_value', 'new_value', 'detected_at'];

    protected $casts = ['detected_at' => 'datetime'];

    public function nestTender()
    {
        return $this->belongsTo(NestTender::class);
    }
}
