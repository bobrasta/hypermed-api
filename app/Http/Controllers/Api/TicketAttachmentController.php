<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceTicket;
use App\Models\TicketAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TicketAttachmentController extends Controller
{
    public function index(ServiceTicket $ticket)
    {
        return response()->json([
            'data' => $ticket->attachments()->latest()->get()->map(fn($a) => $this->fmt($a))->values(),
        ]);
    }

    public function store(Request $request, ServiceTicket $ticket)
    {
        // mimes: covers Section 3's accepted-types list (PDF, DOC, DOCX,
        // PNG, JPG) — generic attachments and service-report uploads share
        // this one endpoint/validation, category is just a tag.
        $data = $request->validate([
            'file'     => ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg', 'max:10240'],
            'category' => ['nullable', 'string', 'in:service_report'],
        ]);

        $file = $request->file('file');
        $path = $file->store('ticket-attachments/' . $ticket->id, 'public');

        $attachment = $ticket->attachments()->create([
            'original_name' => $file->getClientOriginalName(),
            'stored_name'   => basename($path),
            'mime_type'     => $file->getClientMimeType(),
            'size'          => $file->getSize(),
            'category'      => $data['category'] ?? null,
        ]);

        return response()->json(['data' => $this->fmt($attachment)], 201);
    }

    public function destroy(ServiceTicket $ticket, TicketAttachment $attachment)
    {
        abort_if($attachment->ticket_id !== $ticket->id, 404);
        Storage::disk('public')->delete('ticket-attachments/' . $ticket->id . '/' . $attachment->stored_name);
        $attachment->delete();
        return response()->json(null, 204);
    }

    private function fmt(TicketAttachment $a): array
    {
        return [
            'id'         => $a->id,
            'name'       => $a->original_name,
            'size'       => $a->size,
            'mime_type'  => $a->mime_type,
            'category'   => $a->category,
            'url'        => Storage::disk('public')->url('ticket-attachments/' . $a->ticket_id . '/' . $a->stored_name),
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }
}
