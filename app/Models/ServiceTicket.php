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

    // Section 6: ServiceTicketController::store() creates the pivot row(s)
    // itself, but it isn't the only thing that ever creates a ServiceTicket
    // — ServiceTicketSeeder (chained from DatabaseSeeder, run manually
    // against production for demo data on 2026-09-21, after this section's
    // own migration/backfill had already run) writes rows directly and has
    // no idea the pivot table exists. Found in production: 24 tickets with
    // zero service_ticket_machines rows. A model-level guarantee here means
    // ANY creation path — this seeder, a factory, code not yet written —
    // can never produce a ticket with no machine list, instead of chasing
    // down every call site by hand.
    protected static function booted(): void
    {
        static::created(function (ServiceTicket $ticket) {
            if ($ticket->machine_id && $ticket->machinePivots()->doesntExist()) {
                $ticket->machinePivots()->create([
                    'machine_id'   => $ticket->machine_id,
                    'status'       => $ticket->status === 'resolved' ? 'done' : 'pending',
                    'completed_at' => $ticket->status === 'resolved' ? $ticket->resolved_at : null,
                ]);
            }
        });
    }

    protected $fillable = [
        'ticket_number', 'machine_id', 'hospital_id', 'ward', 'type',
        'assigned_to', 'status', 'description', 'priority', 'stage',
        'resolution_notes', 'resolved_at', 'acknowledged_at',
        'travelling_at', 'on_site_at', 'repair_at', 'signed_off_at',
        'billing_status', 'billing_decided_by', 'billing_decided_at', 'billing_override_reason',
        'invoice_id',
    ];

    protected $casts = [
        'resolved_at'         => 'datetime',
        'acknowledged_at'     => 'datetime',
        'travelling_at'       => 'datetime',
        'on_site_at'          => 'datetime',
        'repair_at'           => 'datetime',
        'signed_off_at'       => 'datetime',
        'billing_decided_at'  => 'datetime',
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

    // Kept for backward compatibility (Section 0 rule 5) — an old app build
    // that only reads machine/machine_id keeps working. Always kept in sync
    // with the lowest-id active row in machines() by ServiceTicketController
    // whenever a machine is added/removed.
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    // Section 6 of hypermed_claude_code_prompt.md, generalized (2026-09-21)
    // to every ticket type per direct user instruction, not just
    // Installation: the machines this ticket actually covers. Excludes
    // soft-removed lines (see the service_ticket_machines migration).
    public function machines()
    {
        return $this->belongsToMany(Machine::class, 'service_ticket_machines')
            ->using(ServiceTicketMachine::class)
            ->withPivot(['id', 'status', 'completed_at', 'completed_by', 'removed_at', 'removed_by', 'removal_reason'])
            ->wherePivotNull('removed_at')
            ->withTimestamps();
    }

    // Every active pivot row, including removed_at IS NOT NULL ones excluded
    // above — used where the full audit trail (e.g. a removed-machine log)
    // matters, not just the ticket's current machine list.
    public function machinePivots()
    {
        return $this->hasMany(ServiceTicketMachine::class);
    }

    public function pendingMachinesCount(): int
    {
        return $this->machines()->wherePivot('status', 'pending')->count();
    }

    public function isFullyComplete(): bool
    {
        return $this->pendingMachinesCount() === 0;
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

    public function billingDecidedBy()
    {
        return $this->belongsTo(User::class, 'billing_decided_by');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
