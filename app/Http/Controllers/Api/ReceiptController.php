<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApprovalLog;
use App\Models\Receipt;
use App\Models\VendorFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReceiptController extends Controller
{
    // Internal ops staff (same set as VendorFeeController), OR vendor_staff
    // scoped strictly to their own vendor's fee — Section 16.6 #1's "named
    // individual logins to upload documents".
    private function assertUploadAccess(Request $request, VendorFee $fee): void
    {
        $user = $request->user();

        if ($user->isVendorStaff()) {
            abort_if($user->vendor_id !== $fee->vendor_id, 403, 'You can only upload documents for your own vendor.');
            return;
        }

        abort_if(
            ! $user->hasProcurementCreateAuthority()
                && ! $user->hasProcurementApprovalAuthority()
                && ! $user->hasLogisticsDeliverAuthority()
                && ! $user->hasLogisticsReceiveAuthority()
                && ! $user->hasAccountantAuthority()
                && ! $user->hasFinanceApprovalAuthority()
                && ! $user->hasDirectorAuthority(),
            403,
            'You are not authorised to upload receipts.'
        );
    }

    public function store(Request $request, VendorFee $vendorFee)
    {
        $this->assertUploadAccess($request, $vendorFee);
        abort_if($vendorFee->status !== 'pending_receipt', 422, 'Receipts can only be attached while the fee is awaiting receipts.');

        $data = $request->validate([
            'receipt_type' => ['required', 'in:efd,other'],
            'receipt_number' => ['required', 'string', 'max:100'],
            'issuer_name' => ['required', 'string', 'max:255'],
            'issuer_tin' => ['nullable', 'string', 'max:50'],
            'receipt_date' => ['required', 'date'],
            'amount' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'mimes:pdf,png,jpg,jpeg', 'max:10240'],
        ]);

        // 16.5 AC #4: same receipt number + issuer can never be attached
        // twice, even across different fees — checked here (not just the
        // DB's partial unique index, which only covers the issuer_tin-
        // present case) so a blank-TIN vendor's receipts are covered too.
        $duplicate = Receipt::where('receipt_number', $data['receipt_number'])
            ->where(function ($q) use ($data) {
                $q->where('issuer_name', $data['issuer_name']);
                if (! empty($data['issuer_tin'])) {
                    $q->orWhere('issuer_tin', $data['issuer_tin']);
                }
            })
            ->exists();
        abort_if($duplicate, 422, 'This receipt number has already been attached for this issuer.');

        $file = $request->file('file');
        $path = $file->store('vendor-receipts/' . $vendorFee->id, 'public');

        $receipt = $vendorFee->receipts()->create([
            'receipt_type' => $data['receipt_type'],
            'receipt_number' => $data['receipt_number'],
            'issuer_name' => $data['issuer_name'],
            'issuer_tin' => $data['issuer_tin'] ?? null,
            'receipt_date' => $data['receipt_date'],
            'amount' => $data['amount'],
            'file_original_name' => $file->getClientOriginalName(),
            'file_stored_name' => basename($path),
            'file_mime' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);
        ApprovalLog::record($receipt, 'uploaded', $request->user());

        return response()->json(['data' => ['id' => $receipt->id]], 201);
    }

    // Finance (16.6 #2's default) or Accountant — manual action only, no
    // automated government-portal check (16.6 #3).
    public function verify(Request $request, VendorFee $vendorFee, Receipt $receipt)
    {
        abort_if($receipt->vendor_fee_id !== $vendorFee->id, 404);
        $user = $request->user();
        abort_if(! $user->hasFinanceApprovalAuthority() && ! $user->hasAccountantAuthority(), 403,
            'You are not authorised to verify receipts.');
        abort_if($receipt->isVerified(), 422, 'This receipt is already verified.');

        $receipt->update(['verified_by' => $user->id, 'verified_at' => now()]);
        ApprovalLog::record($receipt, 'verified', $user);

        return response()->json(['data' => ['id' => $receipt->id, 'verified' => true]]);
    }

    public function destroy(Request $request, VendorFee $vendorFee, Receipt $receipt)
    {
        abort_if($receipt->vendor_fee_id !== $vendorFee->id, 404);
        abort_if($receipt->isVerified(), 422, 'A verified receipt cannot be removed — reject the fee instead if it was attached in error.');

        $user = $request->user();
        $isOwnUpload = $receipt->uploaded_by === $user->id;
        $isOpsStaff = $user->hasProcurementCreateAuthority() || $user->hasProcurementApprovalAuthority()
            || $user->hasLogisticsDeliverAuthority() || $user->hasLogisticsReceiveAuthority()
            || $user->hasAccountantAuthority() || $user->hasFinanceApprovalAuthority() || $user->hasDirectorAuthority();
        abort_if(! $isOwnUpload && ! $isOpsStaff, 403, 'Not authorised.');

        Storage::disk('public')->delete('vendor-receipts/' . $vendorFee->id . '/' . $receipt->file_stored_name);
        ApprovalLog::record($receipt, 'removed', $user);
        $receipt->delete();

        return response()->json(null, 204);
    }
}
