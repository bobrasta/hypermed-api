<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Letter;
use App\Models\Setting;
use App\Services\DocumentNumberService;
use App\Services\DocumentPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Letters: anyone writes them; they see their own and the ones they sign
// (admin-tier sees all). Each gets the next reference number. The signatory's
// signature is added only when the signatory signs; editing a signed letter
// removes the signature. The company stamp is uploaded by admin-tier.
class LetterController extends Controller
{
    private const STAMP_KEY = 'company_stamp_path';

    private function visible(Request $request)
    {
        $u = $request->user();

        return Letter::with(['signatory:id,name,signature_path', 'createdBy:id,name'])
            ->when(! $u->isAdminTier(), fn ($q) => $q->where(fn ($q) => $q->where('created_by', $u->id)->orWhere('signatory_id', $u->id)));
    }

    private function row(Letter $l, Request $request): array
    {
        $u = $request->user();

        return [
            'id' => $l->id, 'reference_number' => $l->reference_number, 'letter_date' => $l->letter_date?->toDateString(),
            'recipient' => $l->recipient, 'subject' => $l->subject, 'body' => $l->body,
            'signatory' => $l->signatory ? ['id' => $l->signatory->id, 'name' => $l->signatory->name, 'has_signature' => (bool) $l->signatory->signature_path] : null,
            'signatory_position' => $l->signatory_position, 'signed_at' => $l->signed_at?->toIso8601String(),
            'include_stamp' => $l->include_stamp, 'created_by' => $l->createdBy ? ['id' => $l->createdBy->id, 'name' => $l->createdBy->name] : null,
            'created_at' => $l->created_at?->toIso8601String(),
            'can_edit' => $l->created_by === $u->id || $u->isAdminTier(),
            'can_sign' => $l->signatory_id === $u->id,
        ];
    }

    public function index(Request $request)
    {
        $rows = $this->visible($request)->orderByDesc('id')->limit(500)->get();

        return response()->json(['data' => $rows->map(fn ($l) => $this->row($l, $request))->values(),
            'meta' => ['has_stamp' => (bool) Setting::get(self::STAMP_KEY), 'my_signature' => (bool) $request->user()->signature_path,
                'can_manage_stamp' => $request->user()->isAdminTier()]]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'letter_date'        => ['required', 'date'],
            'recipient'          => ['required', 'string', 'max:2000'],
            'subject'            => ['required', 'string', 'max:300'],
            'body'               => ['required', 'string', 'max:50000'],
            'signatory_id'       => ['nullable', 'exists:users,id'],
            'signatory_position' => ['nullable', 'string', 'max:120'],
            'include_stamp'      => ['sometimes', 'boolean'],
        ]);
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        $data = $this->validated($request);
        $letter = Letter::create($data + ['reference_number' => $numbers->next('letter'), 'created_by' => $request->user()->id]);

        return response()->json(['data' => $this->row($letter->load(['signatory', 'createdBy']), $request)], 201);
    }

    public function update(Request $request, Letter $letter)
    {
        $u = $request->user();
        abort_unless($letter->created_by === $u->id || $u->isAdminTier(), 403, 'Only the writer can edit this letter.');
        $data = $this->validated($request);
        // A changed letter needs signing again.
        $letter->update($data + ['signed_at' => null]);

        return response()->json(['data' => $this->row($letter->fresh(['signatory', 'createdBy']), $request)]);
    }

    public function destroy(Request $request, Letter $letter)
    {
        $u = $request->user();
        abort_unless($letter->created_by === $u->id || $u->isAdminTier(), 403, 'Only the writer can delete this letter.');
        abort_if($letter->signed_at !== null, 422, 'A signed letter cannot be deleted.');
        $letter->delete();

        return response()->noContent();
    }

    public function sign(Request $request, Letter $letter)
    {
        $u = $request->user();
        abort_unless($letter->signatory_id === $u->id, 403, 'Only the signatory can sign this letter.');
        abort_unless($u->signature_path, 422, 'Upload your signature first (Letters → My signature).');
        $letter->update(['signed_at' => now()]);

        return response()->json(['data' => $this->row($letter->fresh(['signatory', 'createdBy']), $request)]);
    }

    public function unsign(Request $request, Letter $letter)
    {
        abort_unless($letter->signatory_id === $request->user()->id, 403, 'Only the signatory can remove their signature.');
        $letter->update(['signed_at' => null]);

        return response()->json(['data' => $this->row($letter->fresh(['signatory', 'createdBy']), $request)]);
    }

    /** My signature image (PNG/JPG, transparent PNG looks best). */
    public function uploadSignature(Request $request)
    {
        $file = $request->validate(['file' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048']])['file'];
        $u = $request->user();
        if ($u->signature_path) {
            Storage::disk('local')->delete($u->signature_path);
        }
        $u->forceFill(['signature_path' => $file->store('signatures', 'local')])->save();

        return response()->json(['data' => ['my_signature' => true]]);
    }

    public function removeSignature(Request $request)
    {
        $u = $request->user();
        if ($u->signature_path) {
            Storage::disk('local')->delete($u->signature_path);
        }
        $u->forceFill(['signature_path' => null])->save();

        return response()->json(['data' => ['my_signature' => false]]);
    }

    public function uploadStamp(Request $request)
    {
        abort_unless($request->user()->isAdminTier(), 403, 'Only an administrator can set the company stamp.');
        $file = $request->validate(['file' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048']])['file'];
        if ($old = Setting::get(self::STAMP_KEY)) {
            Storage::disk('local')->delete($old);
        }
        Setting::set(self::STAMP_KEY, $file->store('stamps', 'local'), $request->user()->id);

        return response()->json(['data' => ['has_stamp' => true]]);
    }

    public function pdf(Request $request, Letter $letter, DocumentPdfService $pdf)
    {
        abort_unless($this->visible($request)->whereKey($letter->id)->exists(), 403, 'Not your letter.');
        $letter->loadMissing('signatory');
        $img = function (?string $path, string $style) {
            if (! $path || ! Storage::disk('local')->exists($path)) {
                return '';
            }
            return "<img src='" . e(Storage::disk('local')->path($path)) . "' style='{$style}'/>";
        };
        $signature = $letter->signed_at ? $img($letter->signatory?->signature_path, 'height:16mm;width:auto') : '';
        $stamp = $letter->include_stamp ? $img(Setting::get(self::STAMP_KEY), 'height:28mm;width:auto') : '';
        $para = fn ($t) => implode('', array_map(fn ($p) => '<p style="margin:0 0 9px;line-height:1.55;text-align:justify">' . nl2br(e(trim($p))) . '</p>',
            preg_split("/\n\s*\n/", trim($t))));

        $body = "<div style='font-size:10.5pt;color:#1d1f20'>"
            . "<table style='width:100%;margin-top:4px'><tr><td style='font-size:10pt'><strong>Our Ref:</strong> " . e($letter->reference_number) . "</td>"
            . "<td style='text-align:right;font-size:10pt'>" . e($letter->letter_date->format('d F Y')) . '</td></tr></table>'
            . "<div style='margin:14px 0 12px;line-height:1.5'>" . nl2br(e($letter->recipient)) . '</div>'
            . "<div style='margin:6px 0 12px;font-weight:700;text-decoration:underline'>RE: " . e(mb_strtoupper($letter->subject)) . '</div>'
            . $para($letter->body)
            . "<div style='margin-top:14px'>Yours faithfully,</div>"
            . "<table style='width:100%;margin-top:4px'><tr><td style='vertical-align:bottom;width:60%'>"
            . "<div style='height:17mm'>{$signature}</div>"
            . "<div style='border-top:1px solid #1d1f20;width:62mm;padding-top:4px'><strong>" . e($letter->signatory?->name ?? '') . '</strong>'
            . ($letter->signatory_position ? '<br>' . e($letter->signatory_position) : '') . '<br>For: HYPERMED HEALTH CARE LIMITED</div>'
            . "</td><td style='vertical-align:bottom;text-align:right'>{$stamp}</td></tr></table></div>";

        return $pdf->letterhead([
            'title' => 'LETTER', 'docLabel' => 'Our ref', 'docNumber' => $letter->reference_number,
            'date' => $letter->letter_date->format('d M Y'),
            // Only an unsigned draft is marked; a signed letter goes out clean.
            'tag' => $letter->signed_at ? '' : 'DRAFT · NOT SIGNED',
            'metaCells' => [],
            'bodyHtml' => $body, 'filename' => str_replace('/', '-', $letter->reference_number) . '.pdf',
        ]);
    }
}
