<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\VendorBillResource;
use App\Models\ApprovalLog;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Creditors\CreditorService;
use App\Services\DocumentNumberService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorBillController extends Controller
{
    use FiltersByPeriod;

    // index()/show() had no gate at all — any of the 15 roles could read
    // full vendor bill records. Reuses screens.finance, the same permission
    // that governs the Finance module's other read screens.
    private function assertFinanceReadAccess(): void
    {
        abort_if(
            ! app(\App\Services\EffectivePermissionResolver::class)->can(\Illuminate\Support\Facades\Auth::user(), 'screens.finance'),
            403,
            'You are not authorised to view finance data.'
        );
    }

    public function index(Request $request)
    {
        $this->assertFinanceReadAccess();

        $query = VendorBill::with(['supplier', 'purchaseOrder', 'category', 'payments', 'approvedBy']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($qb) use ($q) {
                $qb->where('bill_number', 'like', "%$q%")
                   ->orWhereHas('supplier', fn ($q) => $q->where('name', 'like', "%$q%"));
            });
        }

        $this->applyPeriod($query, $request, 'issue_date');

        return VendorBillResource::collection($query->latest('issue_date')->paginate($this->perPage($request, 50)));
    }

    /**
     * A supplier's invoice, in the supplier's currency at the invoice's
     * exchange rate. Posts at once (stock for an LPO invoice, else the
     * category's expense) against Trade Creditors, using any earlier
     * advance first. Payment is requested separately and needs the Director.
     */
    public function store(Request $request, CreditorService $creditors, DocumentNumberService $documentNumbers)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to record vendor bills.');
        $supplier = Supplier::find($request->input('supplier_id'));
        $currency = strtoupper($supplier?->currency ?: 'TZS');

        $data = $request->validate([
            'supplier_id'        => ['required', 'exists:suppliers,id'],
            'supplier_reference' => ['nullable', 'string', 'max:120'],
            'exchange_rate'      => [$currency === 'TZS' ? 'nullable' : 'required', 'numeric', 'min:0.0001'],
            'purchase_order_id'  => ['nullable', 'exists:purchase_orders,id'],
            'category_id'        => ['required_without:purchase_order_id', 'nullable', 'exists:expense_categories,id'],
            'tax_rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'issue_date'         => ['required', 'date'],
            'due_date'           => ['required', 'date', 'after_or_equal:issue_date'],
            'notes'              => ['nullable', 'string'],
            'line_items'         => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'numeric', 'min:0'],
        ], ['exchange_rate.required' => "Enter the invoice's exchange rate (TZS per {$currency})."]);

        if (! empty($data['purchase_order_id'])) {
            $po = PurchaseOrder::findOrFail($data['purchase_order_id']);
            abort_if($po->supplier_id !== (int) $data['supplier_id'], 422, 'That purchase order belongs to another supplier.');
        }

        $lineItems = $data['line_items'];
        unset($data['line_items']);

        // Cents, so foreign invoices add up exactly.
        $lineCents = fn ($i) => (int) round($i['quantity'] * CreditorService::cents($i['unit_price']));
        $subtotal = collect($lineItems)->sum($lineCents);
        $taxRate  = $data['tax_rate'] ?? 0;
        $taxAmount = (int) round($subtotal * $taxRate / 100);

        $data['subtotal']    = $subtotal / 100;
        $data['tax_rate']    = $taxRate;
        $data['tax_amount']  = $taxAmount / 100;
        $data['total']       = ($subtotal + $taxAmount) / 100;
        $data['amount_paid'] = 0;
        $data['status']      = 'pending';
        $data['currency']    = $currency;
        $data['exchange_rate'] = $currency === 'TZS' ? 1 : $data['exchange_rate'];
        $data['bill_number'] = $documentNumbers->next('vendor_bill');
        $data['created_by']  = $request->user()->id;

        $bill = DB::transaction(function () use ($data, $lineItems, $creditors, $request, $lineCents) {
            $bill = VendorBill::create($data);

            foreach ($lineItems as $item) {
                $bill->lineItems()->create([
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => $lineCents($item) / 100,
                ]);
            }

            $creditors->postBill($bill->fresh());
            ApprovalLog::record($bill, 'initiated', $request->user());

            return $bill->fresh();
        });

        return response()->json([
            'data' => new VendorBillResource($bill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments', 'approvedBy'])),
        ], 201);
    }

    public function show(VendorBill $vendorBill)
    {
        $this->assertFinanceReadAccess();

        $vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments.recordedBy', 'approvedBy']);

        return response()->json(['data' => new VendorBillResource($vendorBill)]);
    }

    public function update(Request $request, VendorBill $vendorBill)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to edit vendor bills.');

        $data = $request->validate([
            'due_date' => ['sometimes', 'date'],
            'notes'    => ['nullable', 'string'],
        ]);

        $vendorBill->update($data);

        return response()->json(['data' => new VendorBillResource($vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments', 'approvedBy']))]);
    }

    public function destroy(Request $request, VendorBill $vendorBill, CreditorService $creditors)
    {
        abort_if(! $request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can delete a vendor bill.');

        DB::transaction(function () use ($vendorBill, $creditors) {
            $creditors->reverseBill($vendorBill);
            $vendorBill->delete();
        });

        return response()->json(null, 204);
    }

    public function cancel(Request $request, VendorBill $vendorBill, CreditorService $creditors)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to cancel vendor bills.');
        abort_if($vendorBill->status === 'cancelled', 422, 'This invoice is already cancelled.');

        DB::transaction(function () use ($vendorBill, $creditors) {
            $creditors->reverseBill($vendorBill);
            $vendorBill->update(['status' => 'cancelled', 'amount_paid' => 0]);
            $creditors->refreshPurchaseOrder($vendorBill->purchaseOrder);
        });
        ApprovalLog::record($vendorBill, 'cancelled', $request->user());

        return response()->json(['data' => new VendorBillResource($vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments', 'approvedBy']))]);
    }

    // Director approval, required before any payment can be recorded — the
    // accountant who entered the bill (store()) cannot also approve it.
    public function approve(Request $request, VendorBill $vendorBill)
    {
        abort_if(! $request->user()->hasFinalMoneyAuthority(), 403, 'Only the Director can approve vendor bills for payment.');
        abort_if($vendorBill->status !== 'pending', 422, 'Only a pending bill can be approved.');
        abort_if($vendorBill->created_by === $request->user()->id, 403, 'You cannot approve a vendor bill you recorded.');

        $vendorBill->update([
            'status'      => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);
        ApprovalLog::record($vendorBill, 'approved', $request->user());

        return response()->json(['data' => new VendorBillResource($vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments', 'approvedBy']))]);
    }

    /**
     * Requests a payment of this invoice. It posts only when the Director
     * approves it (Finance → Director, never the same person).
     */
    public function recordPayment(Request $request, VendorBill $vendorBill, DocumentNumberService $documentNumbers)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to request vendor bill payments.');
        abort_if(in_array($vendorBill->status, ['paid', 'cancelled'], true), 422, 'Cannot pay a ' . $vendorBill->status . ' invoice.');

        $payment = app(SupplierPaymentController::class)->initiate($request, $documentNumbers, [
            'supplier_id' => $vendorBill->supplier_id,
            'purchase_order_id' => $vendorBill->purchase_order_id,
            'vendor_bill_id' => $vendorBill->id,
        ]);

        return response()->json([
            'data' => new VendorBillResource($vendorBill->fresh()->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments', 'approvedBy'])),
            'payment' => app(SupplierPaymentController::class)->fmt($payment),
        ], 201);
    }

}
