<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CreditCheckService;
use App\Services\DocumentPdfService;
use App\Services\FinancePostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class InvoiceController extends Controller
{
    // ── PDF / sharing ─────────────────────────────────────────────────────────────

    public function pdf(Invoice $invoice, DocumentPdfService $pdfService)
    {
        return $pdfService->invoicePdf($invoice)->stream("{$invoice->invoice_number}.pdf");
    }

    public function shareLink(Invoice $invoice)
    {
        $expiresAt = now()->addDays(7);
        $url = URL::temporarySignedRoute('invoices.pdf-public', $expiresAt, ['invoice' => $invoice->id]);

        return response()->json(['data' => [
            'share_url'  => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ]]);
    }

    public function index(Request $request)
    {
        $query = Invoice::with(['hospital', 'machine', 'salesOrder', 'payments']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('hospital_id')) {
            $query->where('hospital_id', $request->hospital_id);
        }
        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', $request->sales_order_id);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($qb) use ($q) {
                $qb->where('invoice_number', 'like', "%$q%")
                   ->orWhere('client_name', 'like', "%$q%");
            });
        }
        if ($request->filled('date_from')) {
            $query->where('issue_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('issue_date', '<=', $request->date_to);
        }

        return InvoiceResource::collection($query->latest('issue_date')->paginate(50));
    }

    public function store(Request $request, CreditCheckService $creditCheck, FinancePostingService $financePosting)
    {
        $data = $request->validate([
            'hospital_id'  => ['nullable', 'exists:hospitals,id'],
            'machine_id'   => ['nullable', 'exists:machines,id'],
            'client_name'  => ['nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email'],
            'issue_date'   => ['required', 'date'],
            'due_date'     => ['required', 'date', 'after_or_equal:issue_date'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency'     => ['nullable', 'string', 'max:10'],
            'notes'        => ['nullable', 'string'],
            'line_items'   => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'integer', 'min:0'],
        ]);

        $lineItems = $data['line_items'];
        unset($data['line_items']);

        $subtotal  = collect($lineItems)->sum(fn ($i) => (int) ($i['quantity'] * $i['unit_price']));
        $taxRate   = $data['tax_rate'] ?? 0;
        $taxAmount = (int) round($subtotal * $taxRate / 100);

        $creditCheck->assertWithinLimit(
            isset($data['hospital_id']) ? Hospital::find($data['hospital_id']) : null,
            $subtotal + $taxAmount,
        );

        $data['subtotal']        = $subtotal;
        $data['tax_rate']        = $taxRate;
        $data['tax_amount']      = $taxAmount;
        $data['total']           = $subtotal + $taxAmount;
        $data['amount_paid']     = 0;
        $data['status']          = 'pending';
        $data['invoice_number']  = $this->nextInvoiceNumber();

        $invoice = DB::transaction(function () use ($data, $lineItems, $financePosting) {
            $invoice = Invoice::create($data);

            foreach ($lineItems as $item) {
                $invoice->lineItems()->create([
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => (int) ($item['quantity'] * $item['unit_price']),
                ]);
            }

            $financePosting->postInvoiceIssued($invoice);

            return $invoice;
        });

        return response()->json([
            'data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments'])),
        ], 201);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments.recordedBy']);

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    public function update(Request $request, Invoice $invoice)
    {
        $data = $request->validate([
            'hospital_id'  => ['sometimes', 'nullable', 'exists:hospitals,id'],
            'machine_id'   => ['nullable', 'exists:machines,id'],
            'client_name'  => ['sometimes', 'nullable', 'string'],
            'issue_date'   => ['sometimes', 'date'],
            'due_date'     => ['sometimes', 'date'],
            'notes'        => ['nullable', 'string'],
        ]);

        $invoice->update($data);

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments']))]);
    }

    public function destroy(Invoice $invoice, FinancePostingService $financePosting)
    {
        DB::transaction(function () use ($invoice, $financePosting) {
            $financePosting->reverseInvoice($invoice->load('payments'));
            $invoice->delete();
        });

        return response()->json(null, 204);
    }

    // ── Status transitions ────────────────────────────────────────────────────────

    public function send(Invoice $invoice)
    {
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be sent.'], 422);
        }

        $invoice->update(['status' => 'sent']);

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments']))]);
    }

    public function cancel(Invoice $invoice, FinancePostingService $financePosting)
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Paid invoices cannot be cancelled.'], 422);
        }

        DB::transaction(function () use ($invoice, $financePosting) {
            $financePosting->reverseInvoice($invoice->load('payments'));
            $invoice->update(['status' => 'cancelled']);
        });

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments']))]);
    }

    // ── Payment recording ─────────────────────────────────────────────────────────

    public function recordPayment(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        if (in_array($invoice->status, ['paid', 'cancelled', 'waived'])) {
            return response()->json(['message' => 'Cannot record payment on a ' . $invoice->status . ' invoice.'], 422);
        }

        $data = $request->validate([
            'amount'         => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank_transfer,mobile_money,cheque'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'paid_at'        => ['required', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $data['invoice_id']     = $invoice->id;
        $data['recorded_by']    = $request->user()->id;
        $data['payment_number'] = $this->nextPaymentNumber();

        $payment = DB::transaction(function () use ($data, $invoice, $financePosting) {
            $payment = Payment::create($data);

            $totalPaid = $invoice->payments()->sum('amount');

            $newStatus = match (true) {
                $totalPaid >= $invoice->total => 'paid',
                $totalPaid > 0               => 'partial',
                default                      => $invoice->status,
            };

            $invoice->update([
                'amount_paid' => $totalPaid,
                'status'      => $newStatus,
                'paid_at'     => $newStatus === 'paid' ? now() : null,
            ]);

            $financePosting->postPaymentReceived($invoice, $payment);

            return $payment;
        });

        return response()->json([
            'data'    => new PaymentResource($payment->load('recordedBy')),
            'invoice' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments'])),
        ], 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────

    private function nextInvoiceNumber(): string
    {
        $year = date('Y');
        $last = Invoice::where('invoice_number', 'like', "INV-$year-%")
                       ->orderByDesc('id')->value('invoice_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'INV-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }

    private function nextPaymentNumber(): string
    {
        $year = date('Y');
        $last = Payment::where('payment_number', 'like', "PAY-$year-%")
                       ->orderByDesc('id')->value('payment_number');
        $seq  = $last ? ((int) substr($last, -4) + 1) : 1;
        return 'PAY-' . $year . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
