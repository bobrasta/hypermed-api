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
            'machine_id'     => $this->machine_id,
            'machine'        => new MachineResource($this->whenLoaded('machine')),
            'hospital_id'    => $this->hospital_id,
            'hospital'       => new HospitalResource($this->whenLoaded('hospital')),
            'ward'           => $this->ward,
            'assigned_to'    => $this->assigned_to,
            'assignee'       => new UserResource($this->whenLoaded('assignee')),
            'status'         => $this->status,
            'description'      => $this->description,
            'resolution_notes' => $this->resolution_notes,
            'resolved_at'      => $this->resolved_at?->toIso8601String(),
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
