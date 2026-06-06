<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    protected $fillable = ['ticket_id', 'original_name', 'stored_name', 'mime_type', 'size'];

    public function ticket()
    {
        return $this->belongsTo(ServiceTicket::class, 'ticket_id');
    }
}
