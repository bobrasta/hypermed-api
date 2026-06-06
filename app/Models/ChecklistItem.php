<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChecklistItem extends Model
{
    protected $fillable = ['ticket_id', 'label', 'is_checked'];

    protected $casts = ['is_checked' => 'boolean'];

    public function ticket()
    {
        return $this->belongsTo(ServiceTicket::class, 'ticket_id');
    }
}
