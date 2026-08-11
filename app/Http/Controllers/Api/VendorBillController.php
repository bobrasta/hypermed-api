<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorBillResource;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VendorBillController extends Controller
{
    public function index(Request $request)
    {
        $query = VendorBill::with(['supplier', 'purchaseOrder', 'category', 'payments']);

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

        return VendorBillResource::collection($query->latest('issue_date')->paginate(50));
    }

    public function store(Request $request, FinancePostingService $financePosting)
    {
        $data = $request->validate([
            'supplier_id'        => ['required', 'exists:suppliers,id'],
            'purchase_order_id'  => ['nullable', 'exists:purchase_orders,id'],
            'category_id'        => ['required_without:purchase_order_id', 'nullable', 'exists:expense_categories,id'],
            'tax_rate'           => ['nullable', 'numeric', 'min:0', 'max:100'],
            'issue_date'         => ['required', 'date'],
            'due_date'           => ['required', 'date', 'after_or_equal:issue_date'],
            'notes'              => ['nullable', 'string'],
            'line_items'         => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'integer', 'min:0'],
        ]);

        $lineItems = $data['line_items'];
        unset($data['line_items']);

        $subtotal = collect($lineItems)->sum(fn ($i) => (int) ($i['quantity'] * $i['unit_price']));
        $taxRate  = $data['tax_rate'] ?? 0;
        $taxAmount = (int) round($subtotal * $taxRate / 100);

        $data['subtotal']    = $subtotal;
        $data['tax_rate']    = $taxRate;
        $data['tax_amount']  = $taxAmount;
        $data['total']       = $subtotal + $taxAmount;
        $data['amount_paid'] = 0;
        $data['status']      = 'pending';
        $data['currency']    = 'TZS';
        $data['bill_number'] = $this->nextBillNumber();
        $data['created_by']  = $request->user()->id;

        $bill = DB::transaction(function () use ($data, $lineItems, $financePosting) {
            $bill = VendorBill::create($data);

            foreach ($lineItems as $item) {
                $bill->lineItems()->create([
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => (int) ($item['quantity'] * $item['unit_price']),
                ]);
            }

            $financePosting->postVendorBillIssued($bill);

            return $bill;
        });

        return response()->json([
            'data' => new VendorBillResource($bill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments'])),
        ], 201);
    }

    public function show(VendorBill $vendorBill)
    {
        $vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments.recordedBy']);

        return response()->json(['data' => new VendorBillResource($vendorBill)]);
    }

    public function update(Request $request, VendorBill $vendorBill)
    {
        $data = $request->validate([
            'due_date' => ['sometimes', 'date'],
            'notes'    => ['nullable', 'string'],
        ]);

        $vendorBill->update($data);

        return response()->json(['data' => new VendorBillResource($vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments']))]);
    }

    public function destroy(VendorBill $vendorBill, FinancePostingService $financePosting)
    {
        DB::transaction(function () use ($vendorBill, $financePosting) {
            $financePosting->reverseVendorBill($vendorBill->load('payments'));
            $vendorBill->delete();
        });

        return response()->json(null, 204);
    }

    public function cancel(VendorBill $vendorBill, FinancePostingService $financePosting)
    {
        if ($vendorBill->status === 'paid') {
            return response()->json(['message' => 'Paid bills cannot be cancelled.'], 422);
        }

        DB::transaction(function () use ($vendorBill, $financePosting) {
            $financePosting->reverseVendorBill($vendorBill->load('payments'));
            $vendorBill->update(['status' => 'cancelled']);
        });

        return response()->json(['data' => new VendorBillResource($vendorBill->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments']))]);
    }

    public function recordPayment(Request $request, VendorBill $vendorBill, FinancePostingService $financePosting)
    {
        if (in_array($vendorBill->status, ['paid', 'cancelled'])) {
            return response()->json(['message' => 'Cannot record payment on a ' . $vendorBill->status . ' bill.'], 422);
        }

        $data = $request->validate([
            'amount'         => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank_transfer,mobile_money,cheque'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'paid_at'        => ['required', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $data['vendor_bill_id'] = $vendorBill->id;
        $data['recorded_by']    = $request->user()->id;
        $data['payment_number'] = $this->nextPaymentNumber();

        $payment = DB::transaction(function () use ($data, $vendorBill, $financePosting) {
            $payment = VendorBillPayment::create($data);

            $totalPaid = $vendorBill->payments()->sum('amount');

            $newStatus = match (true) {
                $totalPaid >= $vendorBill->total => 'paid',
                $totalPaid > 0                   => 'partial',
                default                           => $vendorBill->status,
            };

            $vendorBill->update(['amount_paid' => $totalPaid, 'status' => $newStatus]);

            $financePosting->postVendorBillPayment($vendorBill, $payment);

            return $payment;
        });

        return response()->json([
            'data' => new VendorBillResource($vendorBill->fresh()->load(['supplier', 'purchaseOrder', 'category', 'lineItems', 'payments'])),
            'payment_id' => $payment->id,
        ], 201);
    }

    private function nextBillNumber(): string
    {
        $year = date('Y');
        $last = VendorBill::where('bill_number', 'like', "BILL-$year-%")
                          ->orderByDesc('id')->value('bill_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'BILL-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    private function nextPaymentNumber(): string
    {
        $year = date('Y');
        $last = VendorBillPayment::where('payment_number', 'like', "BPAY-$year-%")
                                 ->orderByDesc('id')->value('payment_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'BPAY-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
