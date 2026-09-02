<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreditNoteResource;
use App\Models\ApprovalLog;
use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Http\Request;

class CreditNoteController extends Controller
{
    public function index(Invoice $invoice)
    {
        return CreditNoteResource::collection(
            $invoice->creditNotes()->with(['createdBy', 'approvedBy'])->latest()->get()
        );
    }

    public function store(Request $request, Invoice $invoice)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to issue credit notes.');

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'amount' => ['required', 'integer', 'min:1', 'max:' . $invoice->balance_due],
        ]);

        $last = CreditNote::orderByDesc('id')->first();
        $nextNum = $last ? ((int) preg_replace('/\D/', '', $last->credit_note_number) + 1) : 1;

        $creditNote = CreditNote::create([
            'credit_note_number' => 'CN-' . str_pad((string) $nextNum, 5, '0', STR_PAD_LEFT),
            'invoice_id'         => $invoice->id,
            'reason'             => $data['reason'],
            'amount'             => $data['amount'],
            'status'             => 'draft',
            'created_by'         => $request->user()->id,
        ]);
        ApprovalLog::record($creditNote, 'initiated', $request->user());

        return (new CreditNoteResource($creditNote->load('createdBy')))->response()->setStatusCode(201);
    }

    // Gated to Director authority, not the same accountant permission used
    // to create the note — the accountant who issued a credit note should
    // not also be able to approve their own issuance (segregation of
    // duties).
    public function approve(Request $request, CreditNote $creditNote)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can approve credit notes.');
        abort_if($creditNote->status !== 'draft', 422, 'Only a draft credit note can be approved.');
        abort_if($creditNote->created_by === $request->user()->id, 403, 'You cannot approve a credit note you issued.');

        $creditNote->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
        ]);
        ApprovalLog::record($creditNote, 'approved', $request->user());

        return new CreditNoteResource($creditNote->load(['createdBy', 'approvedBy']));
    }

    public function apply(Request $request, CreditNote $creditNote)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to apply credit notes.');
        abort_if($creditNote->status !== 'approved', 422, 'Only an approved credit note can be applied.');

        $creditNote->update([
            'status'     => 'applied',
            'applied_at' => now(),
        ]);
        ApprovalLog::record($creditNote, 'applied', $request->user());

        return new CreditNoteResource($creditNote->load(['createdBy', 'approvedBy']));
    }
}
