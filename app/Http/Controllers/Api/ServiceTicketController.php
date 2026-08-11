<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceTicketResource;
use App\Models\AppNotification;
use App\Models\ChecklistItem;
use App\Models\PartCannibalization;
use App\Models\SerialNumber;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ServiceTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceTicket::with(['machine', 'hospital', 'assignee']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('machine_id')) {
            $query->where('machine_id', $request->machine_id);
        }
        if ($request->filled('hospital_id')) {
            $query->where('hospital_id', $request->hospital_id);
        }
        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // See InventoryController::index() — same reasoning: callers load a
        // big batch once and reveal/filter locally, don't silently truncate.
        $perPage = min($request->integer('per_page', 20), 1000);

        return ServiceTicketResource::collection($query->latest()->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'machine_id'        => ['required', 'exists:machines,id'],
            'hospital_id'       => ['required', 'exists:hospitals,id'],
            'ward'              => ['nullable', 'string'],
            'assigned_to'       => ['nullable', 'exists:users,id'],
            'status'            => ['required', 'in:open,in_progress,resolved,overdue'],
            'description'       => ['nullable', 'string'],
            'checklist'         => ['nullable', 'array'],
            'checklist.*.label' => ['required_with:checklist', 'string'],
        ]);

        $lastTicket = ServiceTicket::orderByDesc('id')->first();
        $nextNum = $lastTicket ? ((int) ltrim($lastTicket->ticket_number, '#') + 1) : 1000;
        $data['ticket_number'] = '#' . $nextNum;

        $checklist = $data['checklist'] ?? [];
        unset($data['checklist']);

        $ticket = ServiceTicket::create($data);

        foreach ($checklist as $item) {
            $ticket->checklistItems()->create(['label' => $item['label'], 'is_checked' => false]);
        }

        if ($ticket->assigned_to) {
            $this->notifyAssignee($ticket);
        }

        return response()->json([
            'data' => new ServiceTicketResource($ticket->load(['machine', 'hospital', 'assignee', 'checklistItems'])),
        ], 201);
    }

    public function show(ServiceTicket $ticket)
    {
        $ticket->load(['machine.hospital', 'hospital', 'assignee', 'checklistItems', 'partsUsed.inventoryItem', 'attachments']);

        return response()->json(['data' => new ServiceTicketResource($ticket)]);
    }

    public function update(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'machine_id'  => ['sometimes', 'exists:machines,id'],
            'hospital_id' => ['sometimes', 'exists:hospitals,id'],
            'ward'        => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'status'      => ['sometimes', 'in:open,in_progress,resolved,overdue'],
            'description' => ['nullable', 'string'],
        ]);

        $reassignedTo = array_key_exists('assigned_to', $data)
            && $data['assigned_to'] !== null
            && $data['assigned_to'] !== $ticket->assigned_to
            ? $data['assigned_to'] : null;

        $ticket->update($data);

        if ($reassignedTo) {
            $this->notifyAssignee($ticket);
        }

        return response()->json(['data' => new ServiceTicketResource($ticket->load(['machine', 'hospital', 'assignee', 'checklistItems']))]);
    }

    private function notifyAssignee(ServiceTicket $ticket): void
    {
        $machine = $ticket->machine?->model ?? 'a machine';

        AppNotification::create([
            'user_id'     => $ticket->assigned_to,
            'type'        => 'ticket_assigned',
            'title'       => 'Service Ticket Assigned',
            'body'        => "You've been assigned {$ticket->ticket_number} — {$machine}"
                . ($ticket->description ? ": {$ticket->description}" : ''),
            'entity_type' => 'service_ticket',
            'entity_id'   => $ticket->id,
            'is_read'     => false,
        ]);
    }

    public function destroy(ServiceTicket $ticket)
    {
        $ticket->delete();

        return response()->json(null, 204);
    }

    public function resolve(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'resolution_notes'               => ['required', 'string'],
            'parts_used'                     => ['nullable', 'array'],
            'parts_used.*.inventory_item_id' => ['required_with:parts_used', 'exists:inventory_items,id'],
            'parts_used.*.qty'               => ['required_with:parts_used', 'integer', 'min:1'],
            'parts_used.*.unit_cost'         => ['nullable', 'integer', 'min:0'],
            'parts_used.*.source_serial_number_id' => ['nullable', 'exists:serial_numbers,id'],
        ]);

        $ticket->update([
            'status'           => 'resolved',
            'resolution_notes' => $data['resolution_notes'],
            'resolved_at'      => now(),
        ]);

        foreach ($data['parts_used'] ?? [] as $part) {
            $this->createPartUsed($ticket, $part, $request->user()->id);
        }

        return response()->json([
            'data' => new ServiceTicketResource(
                $ticket->load(['machine', 'hospital', 'assignee', 'checklistItems', 'partsUsed.inventoryItem'])
            ),
        ]);
    }

    // Add a part mid-repair, before the ticket is resolved — previously the
    // Flutter "Add Part" dialog called update() with a parts_used payload
    // that update()'s validation rules silently dropped (never persisted, no
    // error either). This is the real endpoint for that action.
    public function addPart(Request $request, ServiceTicket $ticket)
    {
        $data = $request->validate([
            'inventory_item_id'       => ['required', 'exists:inventory_items,id'],
            'qty'                     => ['required', 'integer', 'min:1'],
            'unit_cost'               => ['nullable', 'integer', 'min:0'],
            'source_serial_number_id' => ['nullable', 'exists:serial_numbers,id'],
        ]);

        $this->createPartUsed($ticket, $data, $request->user()->id);

        return response()->json([
            'data' => new ServiceTicketResource(
                $ticket->fresh()->load(['machine', 'hospital', 'assignee', 'checklistItems', 'partsUsed.inventoryItem'])
            ),
        ], 201);
    }

    // Shared by resolve() and addPart(): records the part as used, and if a
    // source serial number is given (part was cannibalized from a stocked
    // unit rather than pulled from generic stock), logs the cannibalization
    // and flags that unit so it can't ship until a replacement is installed.
    private function createPartUsed(ServiceTicket $ticket, array $part, int $userId): void
    {
        DB::transaction(function () use ($ticket, $part, $userId) {
            $partUsed = $ticket->partsUsed()->create([
                'inventory_item_id' => $part['inventory_item_id'],
                'qty'               => $part['qty'],
                'unit_cost'         => $part['unit_cost'] ?? 0,
            ]);

            if (! empty($part['source_serial_number_id'])) {
                $serial = SerialNumber::findOrFail($part['source_serial_number_id']);

                PartCannibalization::create([
                    'source_serial_number_id' => $serial->id,
                    'part_used_id'            => $partUsed->id,
                    'removed_by'              => $userId,
                    'removed_at'              => now(),
                    'status'                  => 'open',
                ]);

                $serial->update(['has_missing_parts' => true]);
            }
        });
    }

    public function toggleChecklist(ServiceTicket $ticket, ChecklistItem $item)
    {
        abort_if($item->ticket_id !== $ticket->id, 404);

        $item->update(['is_checked' => ! $item->is_checked]);

        return response()->json(['data' => ['id' => $item->id, 'is_checked' => $item->is_checked]]);
    }
}
