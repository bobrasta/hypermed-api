<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\DeliveryJob;
use App\Models\User;
use App\Models\VendorFee;
use App\Services\Creditors\CreditorService;
use App\Services\VendorFeeFlow;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorFeeController extends Controller
{
    // Same "who'd plausibly touch this" set as VendorController — vendor
    // fees are created/managed by the same procurement/logistics/finance
    // staff who'd already see the vendor registry. vendor_staff never
    // passes this — it gets its own strictly-scoped read path below instead
    // (the portal it uses to know what to upload against, not general
    // fee-browsing authority).
    private function assertOpsAccess(Request $request): void
    {
        $user = $request->user();
        abort_if(
            ! $user->hasProcurementCreateAuthority()
                && ! $user->hasProcurementApprovalAuthority()
                && ! $user->hasLogisticsDeliverAuthority()
                && ! $user->hasLogisticsReceiveAuthority()
                && ! $user->hasAccountantAuthority()
                && ! $user->hasFinanceApprovalAuthority()
                && ! $user->hasDirectorAuthority(),
            403,
            'You are not authorised to view vendor fees.'
        );
    }

    // No self-approval, mirrors ExpenseController/PerDiemController
    // (Section 1) — a vendor fee is rarely a "personal" submission, but the
    // same guard costs nothing and keeps the audit trail consistent.
    private function abortIfSelfActioning(VendorFee $fee, Request $request): void
    {
        abort_if($fee->created_by === $request->user()->id, 403,
            'You cannot action a vendor fee you created yourself.');
    }

    private function fmt(VendorFee $fee): array
    {
        $fee->loadMissing(['vendor', 'deliveryJob', 'receipts', 'createdBy', 'submittedBy', 'paidBy', 'rejectedBy', 'approver', 'managerApprovedBy']);

        return [
            'id' => $fee->id,
            'vendor' => $fee->vendor ? ['id' => $fee->vendor->id, 'name' => $fee->vendor->name, 'type' => $fee->vendor->type] : null,
            'delivery_job' => $fee->deliveryJob ? [
                'id' => $fee->deliveryJob->id,
                'job_number' => $fee->deliveryJob->job_number,
                'has_delivery_note' => $fee->deliveryJob->hasDeliveryNote(),
                'delivery_note_goods_mismatch' => $fee->deliveryJob->delivery_note_goods_mismatch,
            ] : null,
            'description' => $fee->description,
            'billed_amount' => $fee->billed_amount,
            'currency' => $fee->currency,
            'status' => $fee->status,
            'approver_id' => $fee->approver_id,
            'approver' => $fee->approver?->name,
            'manager_approved_by' => $fee->managerApprovedBy?->name,
            'receipts_total' => (int) $fee->receipts->sum('amount'),
            'outstanding_gap' => $fee->billed_amount - (int) $fee->receipts->sum('amount'),
            'unverified_receipt_count' => $fee->receipts->whereNull('verified_at')->count(),
            'payment_block_reason' => $fee->paymentBlockReason(),
            'receipt_after_payment' => (bool) $fee->receipt_after_payment,
            'receipt_follow_up' => $fee->receiptFollowUp(),
            'status_label' => $fee->statusLabel(),
            'is_payable' => $fee->isPayable(),
            'receipts' => $fee->receipts->map(fn ($r) => [
                'id' => $r->id, 'receipt_type' => $r->receipt_type, 'receipt_number' => $r->receipt_number,
                'issuer_name' => $r->issuer_name, 'issuer_tin' => $r->issuer_tin,
                'receipt_date' => $r->receipt_date?->toDateString(), 'amount' => $r->amount,
                'file_name' => $r->file_original_name,
                'verified' => $r->isVerified(), 'verified_by' => $r->verifiedBy?->name, 'verified_at' => $r->verified_at?->toIso8601String(),
                'uploaded_by' => $r->uploadedBy?->name,
            ]),
            'created_by' => $fee->createdBy?->name,
            'submitted_by' => $fee->submittedBy?->name,
            'submitted_at' => $fee->submitted_at?->toIso8601String(),
            'paid_by' => $fee->paidBy?->name,
            'paid_at' => $fee->paid_at?->toIso8601String(),
            'payment_reference' => $fee->payment_reference,
            'rejected_by' => $fee->rejectedBy?->name,
            'rejected_at' => $fee->rejected_at?->toIso8601String(),
            'rejection_reason' => $fee->rejection_reason,
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = VendorFee::with(['vendor', 'deliveryJob', 'receipts']);

        if ($user->isVendorStaff()) {
            // Forced, not just filtered — a vendor_staff caller can never
            // see another vendor's fees, regardless of what vendor_id it
            // sends. Mirrors LeaveController's mine=1 fix from earlier.
            $query->where('vendor_id', $user->vendor_id);
        } else {
            $this->assertOpsAccess($request);
            if ($request->filled('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        // Approvals: the fees waiting on this person — as the recorder's
        // manager, as Finance (payment request) or as the Director.
        if ($request->boolean('waiting_on_me')) {
            $query->where(function ($q) use ($user) {
                $q->where(fn ($m) => $m->where('status', 'pending_manager')->where('approver_id', $user->id));
                if ($user->hasAccountantAuthority()) {
                    $q->orWhere(fn ($f) => $f->where('status', 'pending_receipt')->where('created_by', '!=', $user->id));
                }
                if ($user->hasFinalMoneyAuthority()) {
                    $q->orWhere(fn ($d) => $d->where('status', 'ready_for_payment')->where('created_by', '!=', $user->id));
                }
            });
        }

        // Returning the paginator directly (not wrapped in another
        // ['data' => ...]) — Laravel serializes it to a flat
        // {current_page, data: [...], last_page, ...} at the top level,
        // which is what unwrapList() on both Flutter and hypermed-web
        // expect. Wrapping it again would double-nest 'data'.
        return $query->latest()->paginate(50)->through(fn ($f) => $this->fmt($f));
    }

    public function show(Request $request, VendorFee $vendorFee)
    {
        $user = $request->user();
        if ($user->isVendorStaff()) {
            abort_if($vendorFee->vendor_id !== $user->vendor_id, 403, 'You can only view your own vendor\'s fees.');
        } else {
            $this->assertOpsAccess($request);
        }

        return response()->json(['data' => $this->fmt($vendorFee)]);
    }

    public function store(Request $request)
    {
        $this->assertOpsAccess($request);

        $data = $request->validate([
            'vendor_id' => ['required', 'exists:vendors,id'],
            'delivery_job_id' => ['nullable', 'exists:delivery_jobs,id'],
            'description' => ['required', 'string', 'max:255'],
            'billed_amount' => ['required', 'numeric', 'min:1'],
            // Vendors (clearing, transport, couriers) bill in shillings.
            'currency' => ['nullable', 'in:TZS'],
        ]);

        if (! empty($data['delivery_job_id'])) {
            $job = DeliveryJob::findOrFail($data['delivery_job_id']);
            // DB-level unique constraint on vendor_fees.delivery_job_id backs
            // this too — this check exists only to return a clean 422
            // instead of a raw constraint-violation 500.
            abort_if($job->fee()->exists(), 422, 'This job has already been billed.');
        }

        $data['created_by'] = $request->user()->id;
        $data['billed_amount'] = (int) round((float) $data['billed_amount']); // whole shillings
        $data['currency'] = $data['currency'] ?? 'TZS';
        $data['status'] = 'pending_receipt';

        $fee = DB::transaction(function () use ($data) {
            $fee = VendorFee::create($data);
            // Owed from the moment it's recorded: cost against Trade Creditors — Local.
            app(CreditorService::class)->postVendorFee($fee);

            return $fee;
        });
        ApprovalLog::record($fee, 'created', $request->user());
        // The recorder's manager first, else Finance — and tell them.
        app(VendorFeeFlow::class)->start($fee);

        if (! empty($data['delivery_job_id'])) {
            DeliveryJob::where('id', $data['delivery_job_id'])->update(['status' => 'billed']);
        }

        return response()->json(['data' => $this->fmt($fee)], 201);
    }

    // The recorder's manager (Patrick for Tender & Procurement) approves
    // first; then it's Finance's to request payment (2026-10-26).
    public function managerApprove(Request $request, VendorFee $vendorFee)
    {
        $this->assertManagerStage($request, $vendorFee);
        $vendorFee->update(['status' => 'pending_receipt', 'manager_approved_by' => $request->user()->id, 'manager_approved_at' => now()]);
        ApprovalLog::record($vendorFee, 'manager_approved', $request->user());
        app(VendorFeeFlow::class)->notifyStage($vendorFee->fresh(['vendor', 'createdBy', 'approver', 'managerApprovedBy']));

        return response()->json(['data' => $this->fmt($vendorFee->fresh())]);
    }

    public function managerReject(Request $request, VendorFee $vendorFee)
    {
        $this->assertManagerStage($request, $vendorFee);
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:10']]);
        $vendorFee->update(['status' => 'rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'rejection_reason' => $data['rejection_reason']]);
        app(CreditorService::class)->reverseVendorFee($vendorFee);
        ApprovalLog::record($vendorFee, 'rejected', $request->user(), $data['rejection_reason']);
        $this->notifyCreator($vendorFee, 'vendor_fee.rejected', approved: false);

        return response()->json(['data' => $this->fmt($vendorFee->fresh())]);
    }

    private function assertManagerStage(Request $request, VendorFee $fee): void
    {
        abort_if($fee->status !== 'pending_manager', 422, 'This fee isn\'t waiting on a manager\'s approval.');
        abort_if($fee->approver_id !== $request->user()->id && ! $request->user()->isAdminTier(), 403,
            'Only ' . ($fee->approver?->name ?? 'the recorder\'s manager') . ' can approve this fee.');
        $this->abortIfSelfActioning($fee, $request);
    }

    // Accountant's move in the Accountant -> Director chain (16.2/16.6 #2's
    // sibling decision). This is the literal enforcement point the spec
    // calls "the payment request/approval endpoint" — re-validates the full
    // 16.2 gate itself rather than trusting anything the client sent.
    public function submitForPayment(Request $request, VendorFee $vendorFee)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to submit a vendor fee for payment.');
        $this->abortIfSelfActioning($vendorFee, $request);
        abort_if($vendorFee->status !== 'pending_receipt', 422, 'Only fees awaiting receipts can be submitted for payment.');

        $reason = $vendorFee->paymentBlockReason();
        abort_if($reason !== null, 422, $reason);

        $vendorFee->update([
            'status' => 'ready_for_payment',
            'submitted_by' => $request->user()->id,
            'submitted_at' => now(),
        ]);
        ApprovalLog::record($vendorFee, 'submitted_for_payment', $request->user());

        $this->notifyDirector($vendorFee);

        return response()->json(['data' => $this->fmt($vendorFee->fresh())]);
    }

    // Director's final release. Deliberately re-checks isPayable() rather
    // than trusting the ready_for_payment status alone — a receipt could in
    // principle be altered between submission and this step, and 16.2 says
    // this must hold "even if the request comes from ... a direct API
    // call", not just be true at submission time.
    public function approve(Request $request, VendorFee $vendorFee)
    {
        abort_if(! $request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can approve a vendor fee for payment.');
        $this->abortIfSelfActioning($vendorFee, $request);
        abort_if($vendorFee->status !== 'ready_for_payment', 422, 'Only fees submitted for payment can be approved.');

        $reason = $vendorFee->paymentBlockReason();
        abort_if($reason !== null, 422, $reason);

        $data = $request->validate([
            // Transfer only, per 16.2 — no cash disbursement to an
            // individual for a vendor fee.
            'payment_reference' => ['required', 'string', 'max:255'],
            // The bank account it's paid from (older app versions: the general bank account).
            'paid_from_account_id' => ['nullable', 'integer'],
        ]);
        $accountId = isset($data['paid_from_account_id']) ? CreditorService::assertPaymentAccount($data['paid_from_account_id'])->id : null;

        DB::transaction(function () use ($vendorFee, $request, $data, $accountId) {
            $vendorFee->update([
                'status' => 'paid',
                'paid_by' => $request->user()->id,
                'paid_at' => now(),
                'payment_reference' => $data['payment_reference'],
                'paid_from_account_id' => $accountId,
            ]);
            app(CreditorService::class)->postVendorFeePaid($vendorFee->fresh());
            if ($vendorFee->delivery_job_id) {
                $vendorFee->deliveryJob()->update(['status' => 'paid']);
            }
            ApprovalLog::record($vendorFee, 'paid', $request->user());
        });

        $this->notifyCreator($vendorFee, 'vendor_fee.paid', approved: true);

        return response()->json(['data' => $this->fmt($vendorFee->fresh())]);
    }

    public function reject(Request $request, VendorFee $vendorFee)
    {
        abort_if(! $request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can reject a vendor fee.');
        $this->abortIfSelfActioning($vendorFee, $request);
        abort_if($vendorFee->status !== 'ready_for_payment', 422, 'Only fees submitted for payment can be rejected.');

        // Mandatory reason, matching Section 9's rejection/cancellation rule.
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:10']]);

        $vendorFee->update([
            'status' => 'rejected',
            'rejected_by' => $request->user()->id,
            'rejected_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);
        // Not owed as billed: out of the books (and the vendor's ledger).
        app(CreditorService::class)->reverseVendorFee($vendorFee);
        ApprovalLog::record($vendorFee, 'rejected', $request->user(), $data['rejection_reason']);

        $this->notifyCreator($vendorFee, 'vendor_fee.rejected', approved: false);

        return response()->json(['data' => $this->fmt($vendorFee->fresh())]);
    }

    private function notifyDirector(VendorFee $fee): void
    {
        User::holdingPermission('authority.final_money_approval')
            ->pluck('id')
            ->each(fn ($id) => AppNotification::create([
                'user_id' => $id,
                ...app(NotificationTemplateService::class)->render('vendor_fee.ready_for_payment', [
                    'vendor_name' => $fee->vendor?->name,
                    'billed_amount' => number_format($fee->billed_amount),
                ]),
                'entity_type' => 'vendor_fee',
                'entity_id' => $fee->id,
                'is_read' => false,
            ]));
    }

    private function notifyCreator(VendorFee $fee, string $templateKey, bool $approved): void
    {
        $reasonSuffix = (! $approved && $fee->rejection_reason) ? " Reason: {$fee->rejection_reason}" : '';

        AppNotification::create([
            'user_id' => $fee->created_by,
            ...app(NotificationTemplateService::class)->render($templateKey, [
                'vendor_name' => $fee->vendor?->name,
                'billed_amount' => number_format($fee->billed_amount),
                'reason_suffix' => $reasonSuffix,
            ]),
            'entity_type' => 'vendor_fee',
            'entity_id' => $fee->id,
            'is_read' => false,
        ]);
    }
}
