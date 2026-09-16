<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentSequence;
use Illuminate\Http\Request;

// Lets an admin see/change the prefix, digit-count and next number for each
// document type (invoices, POs, quotations, ...) without a code deploy —
// e.g. switching "INV" to a company's new preferred prefix, or bumping
// next_number to skip past a block of pre-printed paper forms already used.
class DocumentSequenceController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to view document numbering settings.');

        return response()->json(['data' => DocumentSequence::orderBy('label')->get()]);
    }

    public function update(Request $request, DocumentSequence $documentSequence)
    {
        abort_unless($request->user()->isAdminTier(), 403,
            'Access Denied: you do not have permission to manage document numbering settings.');

        $data = $request->validate([
            'prefix'       => ['sometimes', 'string', 'max:20'],
            'digits'       => ['sometimes', 'integer', 'min:1', 'max:10'],
            'reset_yearly' => ['sometimes', 'boolean'],
            'next_number'  => ['sometimes', 'integer', 'min:1'],
        ]);

        $documentSequence->update($data);

        return response()->json(['data' => $documentSequence]);
    }
}
