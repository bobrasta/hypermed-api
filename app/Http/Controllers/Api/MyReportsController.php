<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Section 7: "My past service reports" — every service_report attachment
// on a ticket the logged-in technician was assigned to. Scoped server-side
// to the caller's own ticket assignments, never a role check — anyone can
// have tickets assigned to them, this just always shows nothing for
// someone who never has.
class MyReportsController extends Controller
{
    public function serviceReports(Request $request)
    {
        $user = $request->user();

        $query = TicketAttachment::query()
            ->where('category', 'service_report')
            ->whereHas('ticket', fn ($q) => $q->where('assigned_to', $user->id))
            ->with(['ticket.hospital', 'ticket.machines']);

        if ($request->filled('hospital_id')) {
            $query->whereHas('ticket', fn ($q) => $q->where('hospital_id', $request->hospital_id));
        }
        if ($request->filled('machine_id')) {
            $query->whereHas('ticket.machines', fn ($q) => $q->where('machines.id', $request->machine_id));
        }
        if ($request->filled('ticket_number')) {
            $query->whereHas('ticket', fn ($q) => $q->where('ticket_number', 'like', "%{$request->ticket_number}%"));
        }
        if ($request->filled('type')) {
            $query->whereHas('ticket', fn ($q) => $q->where('type', $request->type));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $attachments = $query->latest()->get();

        return response()->json(['data' => $attachments->map(fn ($a) => [
            'id'          => $a->id,
            'name'        => $a->original_name,
            'size'        => $a->size,
            'mime_type'   => $a->mime_type,
            'url'         => Storage::disk('public')->url('ticket-attachments/' . $a->ticket_id . '/' . $a->stored_name),
            'created_at'  => $a->created_at?->toIso8601String(),
            'ticket_id'          => $a->ticket->id,
            'ticket_number'      => $a->ticket->ticket_number,
            'ticket_type'        => $a->ticket->type,
            'ticket_resolved_at' => $a->ticket->resolved_at?->toIso8601String(),
            'hospital_name'      => $a->ticket->hospital?->name,
            'machines'           => $a->ticket->machines->map(fn ($m) => [
                'id' => $m->id, 'serial_no' => $m->serial_no, 'model' => $m->model,
            ])->values(),
        ])->values()]);
    }
}
