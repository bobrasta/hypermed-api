<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceTicket extends Model
{
    use HasFactory;

    // Fixed stage sequence for advanceStage() — assigned's "timestamp" is
    // just the ticket's created_at, no column of its own.
    public const STAGES = ['assigned', 'travelling', 'on_site', 'repair', 'signed_off'];

    protected $fillable = [
        'ticket_number', 'machine_id', 'hospital_id', 'ward', 'type',
        'assigned_to', 'status', 'description', 'priority', 'stage',
        'resolution_notes', 'resolved_at', 'acknowledged_at',
        'travelling_at', 'on_site_at', 'repair_at', 'signed_off_at',
    ];

    protected $casts = [
        'resolved_at'      => 'datetime',
        'acknowledged_at'  => 'datetime',
        'travelling_at'    => 'datetime',
        'on_site_at'       => 'datetime',
        'repair_at'        => 'datetime',
        'signed_off_at'    => 'datetime',
    ];

    // The immediate successor of this ticket's current stage in the fixed
    // sequence, or null if already at the final stage.
    public function nextStage(): ?string
    {
        $idx = array_search($this->stage, self::STAGES, true);
        if ($idx === false || ! isset(self::STAGES[$idx + 1])) {
            return null;
        }
        return self::STAGES[$idx + 1];
    }

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
