<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

// Section 6 of hypermed_claude_code_prompt.md — the machines a ticket
// covers. A real Pivot subclass (not the default anonymous one) so it can
// be queried directly (ServiceTicket::machinePivots(), cost-splitting in
// MachineCostService) without always going through the belongsToMany.
class ServiceTicketMachine extends Pivot
{
    protected $table = 'service_ticket_machines';

    public $incrementing = true;

    protected $fillable = [
        'service_ticket_id', 'machine_id', 'status',
        'completed_at', 'completed_by', 'removed_at', 'removed_by', 'removal_reason',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'removed_at'   => 'datetime',
    ];

    public function ticket()
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }
}
