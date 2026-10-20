<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ApprovalLog;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Creditors\CreditorService;
use App\Services\DocumentNumberService;
use App\Services\EffectivePermissionResolver;
use App\Services\NotificationTemplateService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payments to creditors (suppliers, and vendors for their opening balances).
 * Finance initiates; the Director gives final approval (never the same
 * person) and only then the payment posts to the books and the creditor's
 * ledger. LPO payments use this too (PurchaseOrderController delegates).
 */
class SupplierPaymentController extends Controller
{
    public function __construct(private CreditorService $creditors) {}

    public static function canRead(User $u): bool
    {
        return $u->hasAccountantAuthority() || $u->hasFinalMoneyAuthority()
            || app(EffectivePermissionResolver::class)->can($u, 'screens.finance');
    }

    public function index(Request $request)
    {
        abort_unless(self::canRead($request->user()), 403, 'You are not authorised to view payments.');
        $q = SupplierPayment::with(['supplier:id,name,currency', 'vendor:id,name', 'purchaseOrder:id,po_number', 'vendorBill:id,bill_number,supplier_reference',
            'paidFrom:id,code,name', 'initiatedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name'])->latest('id');
        foreach (['supplier_id', 'vendor_id', 'status', 'purchase_order_id', 'vendor_bill_id'] as $f) {
            if ($request->filled($f)) {
                $q->where($f, $request->input($f));
            }
        }

        return response()->json(['data' => $q->limit(500)->get()->map(fn ($p) => $this->fmt($p))]);
    }

    public function accounts(Request $request)
    {
        abort_unless(self::canRead($request->user()), 403);

        return response()->json(['data' => CreditorService::paymentAccounts()->map(fn ($a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name])->values()]);
    }

    public function store(Request $request, DocumentNumberService $numbers)
    {
        abort_unless($request->user()->hasAccountantAuthority(), 403, 'You are not authorised to initiate payments.');
        $data = $request->validate([
            'supplier_id' => ['required_without:vendor_id', 'nullable', 'exists:suppliers,id'],
            'vendor_id' => ['required_without:supplier_id', 'nullable', 'exists:vendors,id'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'vendor_bill_id' => ['nullable', 'exists:vendor_bills,id'],
        ]);
        $payment = $this->initiate($request, $numbers, $data);

        return response()->json(['data' => $this->fmt($payment)], 201);
    }

    /**
     * Shared with the LPO chain. $link holds supplier_id|vendor_id and the
     * optional purchase_order_id / vendor_bill_id.
     */
    public function initiate(Request $request, DocumentNumberService $numbers, array $link): SupplierPayment
    {
        $supplier = ! empty($link['supplier_id']) ? Supplier::findOrFail($link['supplier_id']) : null;
        $vendor = $supplier ? null : Vendor::findOrFail($link['vendor_id']);
        $currency = strtoupper($supplier?->currency ?: 'TZS');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'exchange_rate' => [$currency === 'TZS' ? 'nullable' : 'required', 'numeric', 'min:0.0001'],
            'paid_from_account_id' => ['nullable', 'integer'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['exchange_rate.required' => "Enter the exchange rate (TZS per {$currency})."]);

        if (! empty($link['purchase_order_id'])) {
            $po = PurchaseOrder::findOrFail($link['purchase_order_id']);
            abort_if($supplier && $po->supplier_id !== $supplier->id, 422, 'That purchase order belongs to another supplier.');
        }
        if (! empty($link['vendor_bill_id'])) {
            $bill = VendorBill::findOrFail($link['vendor_bill_id']);
            abort_if(! $supplier || $bill->supplier_id !== $supplier->id, 422, 'That invoice belongs to another supplier.');
            abort_if(in_array($bill->status, ['paid', 'cancelled'], true), 422, 'That invoice is already ' . $bill->status . '.');
        }
        // Older app versions don't send the account: the general bank account.
        $accountId = $data['paid_from_account_id'] ?? null;
        $account = $accountId ? CreditorService::assertPaymentAccount($accountId) : null;
        $rate = $currency === 'TZS' ? 1 : (float) $data['exchange_rate'];
        $cents = CreditorService::cents($data['amount']);

        $payment = SupplierPayment::create([
            'payment_number' => $numbers->next('supplier_payment'),
            'supplier_id' => $supplier?->id,
            'vendor_id' => $vendor?->id,
            'purchase_order_id' => $link['purchase_order_id'] ?? null,
            'vendor_bill_id' => $link['vendor_bill_id'] ?? null,
            'currency' => $currency,
            'amount' => $cents / 100,
            'exchange_rate' => $rate,
            'amount_tzs' => CreditorService::tzs($cents, $rate),
            'paid_from_account_id' => $account?->id ?? \App\Models\ChartOfAccount::where('code', CreditorService::FALLBACK_BANK)->value('id'),
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending_director',
            'initiated_by' => $request->user()->id,
            'initiated_at' => now(),
        ]);
        ApprovalLog::record($payment, 'payment_initiated', $request->user());
        $this->notify($payment, 'director', 'supplier_payment.requested', $request->user());

        return $payment;
    }

    public function approve(Request $request, SupplierPayment $supplierPayment)
    {
        abort_unless($request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can approve payments.');
        $payment = $this->creditors->approvePayment($supplierPayment, $request->user());
        ApprovalLog::record($payment, 'paid', $request->user());
        $this->notify($payment, 'initiator', 'supplier_payment.paid', $request->user());

        return response()->json(['data' => $this->fmt($payment)]);
    }

    public function reject(Request $request, SupplierPayment $supplierPayment)
    {
        abort_unless($request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can reject payments.');
        abort_if($supplierPayment->status !== 'pending_director', 422, 'Only payments awaiting the Director can be rejected.');
        $data = $request->validate(['rejection_reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $supplierPayment->update([
            'status' => 'rejected', 'rejected_by' => $request->user()->id, 'rejected_at' => now(),
            'rejection_reason' => $data['rejection_reason'],
        ]);
        ApprovalLog::record($supplierPayment, 'rejected', $request->user(), $data['rejection_reason']);
        $this->notify($supplierPayment, 'initiator', 'supplier_payment.rejected', $request->user());

        return response()->json(['data' => $this->fmt($supplierPayment->fresh())]);
    }

    private function notify(SupplierPayment $p, string $to, string $template, User $actor): void
    {
        $ids = $to === 'director'
            ? User::holdingPermission('authority.final_money_approval')->where('id', '!=', $actor->id)->pluck('id')
            : collect([$p->initiated_by]);
        $p->loadMissing(['supplier', 'vendor', 'paidFrom', 'purchaseOrder']);
        $vars = [
            'payment_number' => $p->payment_number,
            'actor' => $actor->name,
            'creditor' => $p->creditorName(),
            'amount' => $p->currency . ' ' . number_format((float) $p->amount, 2),
            'account' => $p->paidFrom?->name ?? 'the bank',
            'po_suffix' => $p->purchaseOrder ? " for {$p->purchaseOrder->po_number}" : '',
            'reason' => $p->rejection_reason ?? '',
        ];
        foreach ($ids as $id) {
            AppNotification::create([
                'user_id' => $id,
                ...app(NotificationTemplateService::class)->render($template, $vars),
                'entity_type' => 'supplier_payment',
                'entity_id' => $p->id,
                'is_read' => false,
            ]);
        }
    }

    public function fmt(SupplierPayment $p): array
    {
        $p->loadMissing(['supplier:id,name,currency', 'vendor:id,name', 'purchaseOrder:id,po_number', 'vendorBill:id,bill_number,supplier_reference',
            'paidFrom:id,code,name', 'initiatedBy:id,name', 'approvedBy:id,name', 'rejectedBy:id,name', 'allocations.bill:id,bill_number,supplier_reference']);

        return [
            'id' => $p->id,
            'payment_number' => $p->payment_number,
            'creditor_type' => $p->creditorType(),
            'creditor_id' => $p->creditorId(),
            'creditor_name' => $p->creditorName(),
            'purchase_order' => $p->purchaseOrder ? ['id' => $p->purchaseOrder->id, 'po_number' => $p->purchaseOrder->po_number] : null,
            'vendor_bill' => $p->vendorBill ? ['id' => $p->vendorBill->id, 'bill_number' => $p->vendorBill->bill_number, 'supplier_reference' => $p->vendorBill->supplier_reference] : null,
            'currency' => $p->currency,
            'amount' => (float) $p->amount,
            'exchange_rate' => (float) $p->exchange_rate,
            'amount_tzs' => (int) $p->amount_tzs,
            'paid_from' => $p->paidFrom ? ['id' => $p->paidFrom->id, 'code' => $p->paidFrom->code, 'name' => $p->paidFrom->name] : null,
            'payment_date' => $p->payment_date?->toDateString(),
            'reference' => $p->reference,
            'notes' => $p->notes,
            'status' => $p->status,
            'fx_difference_tzs' => (int) $p->fx_difference_tzs,
            'settled' => $p->allocations->map(fn ($a) => [
                'amount' => (float) $a->amount,
                'kind' => $a->kind,
                'against' => $a->bill ? ($a->bill->supplier_reference ?: $a->bill->bill_number) : 'Opening balance',
            ])->values(),
            'advance' => $p->status === 'paid' ? round((float) $p->amount - (float) $p->allocations->sum('amount'), 2) : null,
            'initiated_by' => $p->initiatedBy?->name,
            'initiated_at' => $p->initiated_at?->toIso8601String(),
            'approved_by' => $p->approvedBy?->name,
            'approved_at' => $p->approved_at?->toIso8601String(),
            'rejected_by' => $p->rejectedBy?->name,
            'rejection_reason' => $p->rejection_reason,
        ];
    }
}
