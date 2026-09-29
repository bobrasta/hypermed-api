<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\CreditCheckService;
use App\Services\DocumentNumberService;
use App\Services\DocumentPdfService;
use App\Services\FinancePostingService;
use App\Services\InvoicePaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use App\Support\Tin;

class InvoiceController extends Controller
{
    use FiltersByPeriod;

    // ── PDF / sharing ─────────────────────────────────────────────────────────────

    public function pdf(Invoice $invoice, DocumentPdfService $pdfService)
    {
        return $pdfService->invoicePdf($invoice);
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

    // Scoped only for the specific 'sales' rep role, not by
    // sales.view_full_numbers generally — this endpoint is shared with
    // Finance/Revenue (accountant, finance_manager also list invoices here
    // for reasons that have nothing to do with sales rep ownership), and
    // neither of those roles holds sales.view_full_numbers either, so
    // gating on that permission instead would have wrongly restricted them
    // to zero invoices (they never created a sales order to match against).
    public function index(Request $request)
    {
        $query = Invoice::with(['hospital', 'machine', 'salesOrder', 'payments']);

        if ($request->user()->role === 'sales') {
            $query->whereHas('salesOrder', fn ($q) => $q->where('created_by', $request->user()->id));
        }

        if ($request->filled('status')) {
            // 'overdue' is never actually stored on the row — it's a point-
            // in-time fact (still unpaid past its due date), not a workflow
            // stage the controller transitions through, the same reasoning
            // arAging() already uses to compute it live rather than store
            // it. Filtering on it has to mean the same thing here.
            if ($request->status === 'overdue') {
                $query->whereIn('status', ['pending', 'sent', 'partial'])
                    ->where('due_date', '<', now()->toDateString());
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->filled('hospital_id')) {
            $query->where('hospital_id', $request->hospital_id);
        }
        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', $request->sales_order_id);
        }
        if ($request->filled('machine_id')) {
            $query->where('machine_id', $request->machine_id);
        }
        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(function ($qb) use ($q) {
                $qb->where('invoice_number', 'like', "%$q%")
                   ->orWhere('client_name', 'like', "%$q%");
            });
        }
        $this->applyPeriod($query, $request, 'issue_date');

        return InvoiceResource::collection($query->latest('issue_date')->paginate($this->perPage($request, 50)));
    }

    public function store(Request $request, CreditCheckService $creditCheck, FinancePostingService $financePosting, DocumentNumberService $documentNumbers, InvoicePaymentService $payments)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to create invoices.');

        $data = $request->validate([
            'hospital_id'  => ['nullable', 'exists:hospitals,id'],
            'machine_id'   => ['nullable', 'exists:machines,id'],
            'client_name'  => ['nullable', 'string', 'max:255'],
            'client_contact' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email'],
            'client_tin'   => ['required', ...Tin::RULE],
            'issue_date'   => ['required', 'date'],
            // Either a payment term (Clickhuduma style: "30 days", "4 months")
            // that sets the due date, or an explicit due date.
            'pay_term_number' => ['nullable', 'integer', 'min:0', 'max:3650', 'required_without:due_date'],
            'pay_term_type'   => ['nullable', 'in:days,months', 'required_with:pay_term_number'],
            'due_date'     => ['nullable', 'date', 'after_or_equal:issue_date', 'required_without:pay_term_number'],
            'tax_rate'     => ['nullable', 'numeric', 'min:0', 'max:100'],
            'shipping_charges' => ['nullable', 'integer', 'min:0'],
            // Optional first payment taken with the invoice (hire-purchase deposit).
            'deposit_amount'   => ['nullable', 'integer', 'min:1'],
            'deposit_method'   => ['nullable', 'in:cash,bank_transfer,mobile_money,cheque', 'required_with:deposit_amount'],
            'deposit_reference'=> ['nullable', 'string', 'max:255'],
            'currency'     => ['nullable', 'string', 'max:10'],
            'notes'        => ['nullable', 'string'],
            'line_items'   => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'integer', 'min:0'],
        ], ['client_tin.regex' => Tin::MESSAGE, 'client_tin.required' => 'Client TIN is required.']);
        $data['client_tin'] = Tin::normalize($data['client_tin']);

        $lineItems = $data['line_items'];
        $deposit = isset($data['deposit_amount']) ? [
            'amount'         => (int) $data['deposit_amount'],
            'payment_method' => $data['deposit_method'],
            'reference'      => $data['deposit_reference'] ?? null,
            'paid_at'        => $data['issue_date'],
            'notes'          => 'Deposit',
        ] : null;
        unset($data['line_items'], $data['deposit_amount'], $data['deposit_method'], $data['deposit_reference']);

        if (isset($data['pay_term_number'])) {
            $issue = \Illuminate\Support\Carbon::parse($data['issue_date']);
            $data['due_date'] = ($data['pay_term_type'] === 'months'
                ? $issue->copy()->addMonthsNoOverflow($data['pay_term_number'])
                : $issue->copy()->addDays($data['pay_term_number']))->toDateString();
        }
        $shipping = (int) ($data['shipping_charges'] ?? 0);
        $data['shipping_charges'] = $shipping;

        $subtotal  = collect($lineItems)->sum(fn ($i) => (int) ($i['quantity'] * $i['unit_price']));
        $taxRate   = $data['tax_rate'] ?? 0;
        $taxAmount = (int) round($subtotal * $taxRate / 100);

        $total = $subtotal + $taxAmount + $shipping;
        if ($deposit && $deposit['amount'] > $total) {
            return response()->json(['message' => 'The deposit is more than the invoice total.', 'errors' => ['deposit_amount' => ['The deposit is more than the invoice total.']]], 422);
        }

        // Only the part left on credit counts against the client's limit.
        $creditCheck->assertWithinLimit(
            isset($data['hospital_id']) ? Hospital::find($data['hospital_id']) : null,
            $total - ($deposit['amount'] ?? 0),
        );

        $data['subtotal']        = $subtotal;
        $data['tax_rate']        = $taxRate;
        $data['tax_amount']      = $taxAmount;
        $data['total']           = $total;
        $data['amount_paid']     = 0;
        $data['status']          = 'pending';
        $data['invoice_number']  = $documentNumbers->next('invoice');

        $invoice = DB::transaction(function () use ($data, $lineItems, $financePosting, $deposit, $payments, $request) {
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
            Tin::rememberOn($invoice->hospital, $invoice->client_tin);

            if ($deposit) {
                $payments->apply($invoice, $deposit, $request->user());
            }

            return $invoice->fresh();
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
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to edit invoices.');

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

    public function destroy(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        abort_if(! $request->user()->hasDirectorAuthority(), 403, 'Only the Director can delete an invoice.');

        DB::transaction(function () use ($invoice, $financePosting) {
            $financePosting->reverseInvoice($invoice->load('payments'));
            $invoice->delete();
        });

        return response()->json(null, 204);
    }

    // ── Status transitions ────────────────────────────────────────────────────────

    public function send(Request $request, Invoice $invoice)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to send invoices.');
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be sent.'], 422);
        }

        $invoice->update(['status' => 'sent']);

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments']))]);
    }

    public function cancel(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to cancel invoices.');
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

    // Recording a customer payment already received doesn't require the
    // same Director gate as money going OUT — it's data entry of cash
    // already in hand, not a commitment. Still restricted to Accountant/
    // admin so an arbitrary authenticated user can't fabricate a payment
    // record (which would misstate revenue and receivables).
    public function recordPayment(Request $request, Invoice $invoice, InvoicePaymentService $payments)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to record invoice payments.');

        $data = $request->validate([
            'amount'         => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank_transfer,mobile_money,cheque'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'paid_at'        => ['required', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $payment = DB::transaction(fn () => $payments->apply($invoice, $data, $request->user()));

        return response()->json([
            'data'    => new PaymentResource($payment->load('recordedBy')),
            'invoice' => new InvoiceResource($invoice->fresh()->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments'])),
        ], 201);
    }

}
