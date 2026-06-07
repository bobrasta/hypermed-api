<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_number', 'machine_id', 'hospital_id', 'ward',
        'assigned_to', 'status', 'description',
        'resolution_notes', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function checklistItems()
    {
        return $this->hasMany(ChecklistItem::class, 'ticket_id');
    }

    public function partsUsed()
    {
        return $this->hasMany(PartUsed::class, 'ticket_id');
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id');
    }
}
