<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceTicketResource;
use App\Models\ChecklistItem;
use App\Models\ServiceTicket;
use Illuminate\Http\Request;

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

        return ServiceTicketResource::collection($query->latest()->paginate(20));
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

        $ticket->update($data);

        return response()->json(['data' => new ServiceTicketResource($ticket->load(['machine', 'hospital', 'assignee', 'checklistItems']))]);
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
        ]);

        $ticket->update([
            'status'           => 'resolved',
            'resolution_notes' => $data['resolution_notes'],
            'resolved_at'      => now(),
        ]);

        foreach ($data['parts_used'] ?? [] as $part) {
            $ticket->partsUsed()->create([
                'inventory_item_id' => $part['inventory_item_id'],
                'qty'               => $part['qty'],
                'unit_cost'         => $part['unit_cost'] ?? 0,
            ]);
        }

        return response()->json([
            'data' => new ServiceTicketResource(
                $ticket->load(['machine', 'hospital', 'assignee', 'checklistItems', 'partsUsed.inventoryItem'])
            ),
        ]);
    }

    public function toggleChecklist(ServiceTicket $ticket, ChecklistItem $item)
    {
        abort_if($item->ticket_id !== $ticket->id, 404);

        $item->update(['is_checked' => ! $item->is_checked]);

        return response()->json(['data' => ['id' => $item->id, 'is_checked' => $item->is_checked]]);
    }
}
