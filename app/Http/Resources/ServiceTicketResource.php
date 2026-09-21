<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ServiceTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'ticket_number'  => $this->ticket_number,
            // machine/machine_id kept for backward compatibility (Section 0
            // rule 5) — always the lowest-id active machine on the ticket,
            // see ServiceTicket::machine(). machines (Section 6, generalized
            // to every ticket type) is the full list with each one's
            // per-machine completion status.
            'machine_id'     => $this->machine_id,
            'machine'        => new MachineResource($this->whenLoaded('machine')),
            'machines'       => $this->whenLoaded('machines', fn () => $this->machines->map(fn ($m) => [
                'id'           => $m->id,
                'serial_no'    => $m->serial_no,
                'model'        => $m->model,
                'status'       => $m->pivot->status,
                'completed_at' => $m->pivot->completed_at?->toIso8601String(),
            ])->values()),
            'machine_count'         => $this->whenLoaded('machines', fn () => $this->machines->count()),
            'pending_machine_count' => $this->whenLoaded('machines', fn () => $this->machines->where('pivot.status', 'pending')->count()),
            'hospital_id'    => $this->hospital_id,
            'hospital'       => new HospitalResource($this->whenLoaded('hospital')),
            'ward'           => $this->ward,
            'type'           => $this->type,
            'assigned_to'    => $this->assigned_to,
            'assignee'       => new UserResource($this->whenLoaded('assignee')),
            'status'         => $this->status,
            'priority'       => $this->priority,
            'stage'          => $this->stage,
            'description'      => $this->description,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at'      => $this->resolved_at?->toIso8601String(),
            'billing_status'          => $this->billing_status,
            'billing_decided_by'      => $this->billing_decided_by,
            'billing_decided_by_name' => $this->billingDecidedBy?->name,
            'billing_decided_at'      => $this->billing_decided_at?->toIso8601String(),
            'billing_override_reason' => $this->billing_override_reason,
            'invoice_id'              => $this->invoice_id,
            'acknowledged_at'  => $this->acknowledged_at?->toIso8601String(),
            'travelling_at'    => $this->travelling_at?->toIso8601String(),
            'on_site_at'       => $this->on_site_at?->toIso8601String(),
            'repair_at'        => $this->repair_at?->toIso8601String(),
            'signed_off_at'    => $this->signed_off_at?->toIso8601String(),
            'checklist'        => ChecklistItemResource::collection($this->whenLoaded('checklistItems')),
            'parts_used'       => PartUsedResource::collection($this->whenLoaded('partsUsed')),
            'attachments'      => $this->whenLoaded('attachments', fn() => $this->attachments->map(fn($a) => [
                'id'         => $a->id,
                'name'       => $a->original_name,
                'size'       => $a->size,
                'mime_type'  => $a->mime_type,
                'url'        => Storage::disk('public')->url('ticket-attachments/' . $a->ticket_id . '/' . $a->stored_name),
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
