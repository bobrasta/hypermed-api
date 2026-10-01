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
use App\Support\DocumentTerms;
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
        $query = Invoice::with(['hospital', 'machine', 'salesOrder', 'payments', 'creator:id,name'])
            ->withSum('lineItems as total_items', 'quantity')
            ->withSum(['creditNotes as credited' => fn ($c) => $c->where('status', 'applied')], 'amount');

        // Final sales by default; sale_status=draft|proforma lists those instead.
        if (in_array($request->input('sale_status'), Invoice::UNFINAL, true)) {
            $query->withUnfinal()->where('invoices.status', $request->input('sale_status'));
        }

        // A sales rep sees the sales they added, plus invoices made from
        // their own sales orders.
        if ($request->user()->role === 'sales') {
            $me = $request->user()->id;
            $query->where(fn ($w) => $w->where('created_by', $me)
                ->orWhereHas('salesOrder', fn ($q) => $q->where('created_by', $me)));
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
        // Payment-status filter (All sales chips): each sale has exactly one
        // of paid / due (nothing paid, not yet late) / partial (part paid,
        // not yet late) / overdue (unpaid past its due date) / cancelled.
        if ($request->filled('payment_status')) {
            $today = now()->toDateString();
            match ($request->payment_status) {
                'paid'    => $query->where('status', 'paid'),
                'due'     => $query->whereIn('status', ['pending', 'sent'])->where('amount_paid', 0)->where('due_date', '>=', $today),
                'partial' => $query->whereIn('status', ['pending', 'sent', 'partial'])->where('amount_paid', '>', 0)->where('due_date', '>=', $today),
                'overdue' => $query->whereIn('status', ['pending', 'sent', 'partial'])->where('due_date', '<', $today),
                default   => $query->where('status', $request->payment_status),
            };
        }
        if ($request->filled('created_by')) {
            $query->where('created_by', $request->created_by);
        }
        if ($request->filled('shipping_status')) {
            $query->where('shipping_status', $request->shipping_status);
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
                $qb->where('invoice_number', 'ilike', "%$q%")
                   ->orWhere('client_name', 'ilike', "%$q%")
                   ->orWhere('client_contact', 'ilike', "%$q%");
            });
        }
        $this->applyPeriod($query, $request, 'issue_date');

        // Sort by column (All sales); newest first by default.
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';
        $sorted = $query->clone();
        match ($request->input('sort')) {
            'number'   => $sorted->orderBy('invoice_number', $dir),
            'customer' => $sorted->orderByRaw("lower(coalesce(client_name, '')) {$dir}"),
            'status'   => $sorted->orderBy('status', $dir),
            'total'    => $sorted->orderBy('total', $dir),
            'paid'     => $sorted->orderBy('amount_paid', $dir),
            'due'      => $sorted->orderByRaw("(case when status in ('pending','sent','partial') then total - amount_paid else 0 end) {$dir}"),
            'added_by' => $sorted->orderByRaw("lower(coalesce((select name from users u where u.id = invoices.created_by), added_by_name, '')) {$dir}"),
            default    => $sorted->orderBy('issue_date', $dir),
        };
        $page = InvoiceResource::collection($sorted->orderBy('id', $dir)->paginate($this->perPage($request, 50)));

        // All sales footer: totals over the whole filtered set, not one page.
        if ($request->boolean('with_totals')) {
            $ids = $query->clone()->select('invoices.id');
            $base = Invoice::withUnfinal()->whereIn('id', $ids);
            $today = now()->toDateString();
            $t = $base->clone()->selectRaw(<<<SQL
                count(*) as count,
                coalesce(sum(total), 0) as total,
                coalesce(sum(amount_paid), 0) as paid,
                count(*) filter (where status = 'paid') as paid_count,
                count(*) filter (where status in ('pending','sent','partial') and due_date < '{$today}') as overdue_count,
                count(*) filter (where status in ('pending','sent','partial') and due_date >= '{$today}' and amount_paid > 0) as partial_count,
                count(*) filter (where status in ('pending','sent') and due_date >= '{$today}' and amount_paid = 0) as due_count
                SQL)->first();
            $open = $base->clone()->whereIn('status', ['pending', 'sent', 'partial'])
                ->selectRaw('coalesce(sum(total - amount_paid), 0) as due')->value('due');
            $methods = \App\Models\Payment::whereIn('invoice_id', $ids)
                ->selectRaw('payment_method, count(distinct invoice_id) as n')->groupBy('payment_method')
                ->pluck('n', 'payment_method');

            $page->additional(['totals' => [
                'count'           => (int) $t->count,
                'total'           => (int) $t->total,
                'paid'            => (int) $t->paid,
                'due'             => (int) $open,
                'payment_status'  => ['paid' => (int) $t->paid_count, 'due' => (int) $t->due_count,
                    'partial' => (int) $t->partial_count, 'overdue' => (int) $t->overdue_count],
                'payment_methods' => $methods,
            ]]);
        }

        return $page;
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
            // Clickhuduma sale Status: final (a real invoice), or saved as a
            // draft / proforma to finish later. Quotations use /quotations.
            'sale_status'  => ['nullable', 'in:final,draft,proforma'],
            // A draft may be saved before the TIN is known.
            'client_tin'   => ['required_unless:sale_status,draft', 'nullable', ...Tin::RULE],
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
            'staff_note'   => ['nullable', 'string'],
            ...DocumentTerms::RULES,
            'line_items'   => ['required', 'array', 'min:1'],
            'line_items.*.description' => ['required', 'string'],
            'line_items.*.quantity'    => ['required', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required', 'integer', 'min:0'],
        ], ['client_tin.regex' => Tin::MESSAGE, 'client_tin.required' => 'Client TIN is required.']);
        $data['client_tin'] = ! empty($data['client_tin']) ? Tin::normalize($data['client_tin']) : null;
        $saleStatus = $data['sale_status'] ?? 'final';
        unset($data['sale_status']);
        $final = $saleStatus === 'final';
        if (! $final && isset($data['deposit_amount'])) {
            return response()->json(['message' => 'Take the deposit when the sale is finalised.', 'errors' => ['deposit_amount' => ['Take the deposit when the sale is finalised.']]], 422);
        }

        $lineItems = $data['line_items'];
        if (array_key_exists('term_items', $data)) {
            $data['term_items'] = DocumentTerms::normalize($data['term_items']);
        }
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
        if ($final) {
            $creditCheck->assertWithinLimit(
                isset($data['hospital_id']) ? Hospital::find($data['hospital_id']) : null,
                $total - ($deposit['amount'] ?? 0),
            );
        }

        $data['subtotal']        = $subtotal;
        $data['tax_rate']        = $taxRate;
        $data['tax_amount']      = $taxAmount;
        $data['total']           = $total;
        $data['amount_paid']     = 0;
        $data['status']          = $final ? 'pending' : $saleStatus;
        $data['invoice_number']  = $documentNumbers->next(match ($saleStatus) { 'draft' => 'sale_draft', 'proforma' => 'proforma', default => 'invoice' });

        $invoice = DB::transaction(function () use ($data, $lineItems, $financePosting, $deposit, $payments, $request, $final) {
            $invoice = Invoice::create($data);

            foreach ($lineItems as $item) {
                $invoice->lineItems()->create([
                    'description' => $item['description'],
                    'quantity'    => $item['quantity'],
                    'unit_price'  => $item['unit_price'],
                    'total'       => (int) ($item['quantity'] * $item['unit_price']),
                ]);
            }

            if ($final) {
                $financePosting->postInvoiceIssued($invoice);
            }
            if ($invoice->client_tin) {
                Tin::rememberOn($invoice->hospital, $invoice->client_tin);
            }

            if ($deposit) {
                $payments->apply($invoice, $deposit, $request->user());
            }

            return $invoice->fresh();
        });

        return response()->json([
            'data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments'])),
        ], 201);
    }

    // Company default TERMS & CONDITIONS — the placeholders on the sale and
    // quotation forms, and what prints wherever a document leaves one blank.
    public function termDefaults()
    {
        return response()->json(['data' => DocumentTerms::defaults()]);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments.recordedBy', 'creator:id,name'])
            ->loadSum(['creditNotes as credited' => fn ($c) => $c->where('status', 'applied')], 'amount');

        return response()->json(['data' => new InvoiceResource($invoice)]);
    }

    // Edit a sale (Clickhuduma "Edit"): client, dates/terms, notes and —
    // when sent — the line items, tax and delivery charge. Totals are
    // recomputed and, if the invoice was posted to the ledger, re-posted.
    public function update(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to edit invoices.');
        abort_if($invoice->status === 'cancelled', 422, 'A cancelled invoice cannot be edited.');

        $data = $request->validate([
            // Switch a draft/proforma between the two, or finalise it.
            'sale_status'     => ['sometimes', 'in:final,draft,proforma'],
            'hospital_id'     => ['sometimes', 'nullable', 'exists:hospitals,id'],
            'machine_id'      => ['nullable', 'exists:machines,id'],
            'client_name'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_contact'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'client_email'    => ['sometimes', 'nullable', 'email'],
            'client_tin'      => ['sometimes', 'nullable', ...Tin::RULE],
            'issue_date'      => ['sometimes', 'date'],
            'due_date'        => ['sometimes', 'date'],
            'pay_term_number' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:3650'],
            'pay_term_type'   => ['sometimes', 'nullable', 'in:days,months', 'required_with:pay_term_number'],
            'tax_rate'        => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'shipping_charges'=> ['sometimes', 'integer', 'min:0'],
            'notes'           => ['nullable', 'string'],
            'staff_note'      => ['nullable', 'string'],
            ...DocumentTerms::RULES,
            'line_items'      => ['sometimes', 'array', 'min:1'],
            'line_items.*.description' => ['required_with:line_items', 'string'],
            'line_items.*.quantity'    => ['required_with:line_items', 'numeric', 'min:0.01'],
            'line_items.*.unit_price'  => ['required_with:line_items', 'integer', 'min:0'],
        ], ['client_tin.regex' => Tin::MESSAGE]);
        if (! empty($data['client_tin'])) {
            $data['client_tin'] = Tin::normalize($data['client_tin']);
        }

        $lineItems = $data['line_items'] ?? null;
        if (array_key_exists('term_items', $data)) {
            $data['term_items'] = DocumentTerms::normalize($data['term_items']);
        }
        $toStatus = $data['sale_status'] ?? null;
        unset($data['line_items'], $data['sale_status']);
        if ($invoice->isFinal() && $toStatus !== null && $toStatus !== 'final') {
            throw \Illuminate\Validation\ValidationException::withMessages(['sale_status' => 'A final sale cannot go back to draft or proforma.']);
        }
        $finalising = ! $invoice->isFinal() && $toStatus === 'final';

        if (isset($data['pay_term_number'])) {
            $issue = \Illuminate\Support\Carbon::parse($data['issue_date'] ?? $invoice->issue_date);
            $data['due_date'] = ($data['pay_term_type'] === 'months'
                ? $issue->copy()->addMonthsNoOverflow($data['pay_term_number'])
                : $issue->copy()->addDays($data['pay_term_number']))->toDateString();
        }

        DB::transaction(function () use ($invoice, $data, $lineItems, $financePosting, $toStatus, $finalising, $request) {
            $invoice->fill($data);
            if ($toStatus && ! $invoice->isFinal() && ! $finalising) {
                // Draft <-> proforma: each has its own number series.
                if ($invoice->status !== $toStatus) {
                    $invoice->status = $toStatus;
                    $invoice->invoice_number = app(DocumentNumberService::class)->next($toStatus === 'draft' ? 'sale_draft' : 'proforma');
                }
            }
            if ($lineItems !== null) {
                $invoice->lineItems()->delete();
                foreach ($lineItems as $item) {
                    $invoice->lineItems()->create([
                        'description' => $item['description'],
                        'quantity'    => $item['quantity'],
                        'unit_price'  => $item['unit_price'],
                        'total'       => (int) ($item['quantity'] * $item['unit_price']),
                    ]);
                }
            }
            $this->recomputeTotals($invoice, $financePosting, $lineItems !== null);

            if ($finalising) {
                $this->finalise($invoice, $financePosting, $request);
            }
        });

        return response()->json(['data' => new InvoiceResource($invoice->fresh()->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments', 'creator:id,name']))]);
    }

    /** Draft/proforma → a real sale: invoice number, credit check, ledger. */
    private function finalise(Invoice $invoice, FinancePostingService $financePosting, Request $request): void
    {
        if (! $invoice->client_tin && ! $invoice->hospital?->tin) {
            throw \Illuminate\Validation\ValidationException::withMessages(['client_tin' => 'Client TIN is required before the sale is finalised.']);
        }
        app(CreditCheckService::class)->assertWithinLimit($invoice->hospital, (int) $invoice->total);

        $invoice->status = 'pending';
        $invoice->invoice_number = app(DocumentNumberService::class)->next('invoice');
        $invoice->save();
        $financePosting->postInvoiceIssued($invoice);
        if ($invoice->client_tin) {
            Tin::rememberOn($invoice->hospital, $invoice->client_tin);
        }
    }

    // Edit Shipping (Clickhuduma): delivery details, status and charge.
    public function shipping(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        abort_if(! $request->user()->hasAccountantAuthority() && ! $request->user()->hasSalesEditAuthority(), 403,
            'You are not authorised to edit shipping.');

        $data = $request->validate([
            'shipping_details' => ['nullable', 'string'],
            'shipping_address' => ['nullable', 'string'],
            'shipping_charges' => ['sometimes', 'integer', 'min:0'],
            'shipping_status'  => ['nullable', 'in:' . implode(',', Invoice::SHIPPING_STATUSES)],
            'delivered_to'     => ['nullable', 'string', 'max:255'],
        ]);
        // The charge changes the invoice total, which is accountant work.
        if (array_key_exists('shipping_charges', $data) && (int) $data['shipping_charges'] !== (int) $invoice->shipping_charges) {
            abort_if(! $request->user()->hasAccountantAuthority(), 403, 'Only an accountant can change the shipping charge.');
            abort_if($invoice->status === 'cancelled', 422, 'A cancelled invoice cannot be changed.');
        }

        DB::transaction(function () use ($invoice, $data, $financePosting) {
            $invoice->fill($data);
            $this->recomputeTotals($invoice, $financePosting, false);
        });

        return response()->json(['data' => new InvoiceResource($invoice->fresh()->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments', 'creator:id,name']))]);
    }

    /**
     * Recalculates subtotal/tax/total after an edit and saves. If the
     * total changed and the invoice had been posted to the ledger, its
     * issue posting is replaced (imported Clickhuduma invoices were never
     * posted, so they stay out of the ledger). The new total can't drop
     * below what has already been paid or credited.
     */
    private function recomputeTotals(Invoice $invoice, FinancePostingService $financePosting, bool $linesChanged): void
    {
        $subtotal = $linesChanged || $invoice->isDirty('tax_rate')
            ? (int) $invoice->lineItems()->sum('total')
            : (int) $invoice->subtotal;
        $taxAmount = $linesChanged || $invoice->isDirty('tax_rate')
            ? (int) round($subtotal * ((float) $invoice->tax_rate) / 100)
            : (int) $invoice->tax_amount;
        $total = $subtotal + $taxAmount + (int) $invoice->shipping_charges;
        $oldTotal = (int) $invoice->getOriginal('total');

        $credited = (int) $invoice->creditNotes()->where('status', 'applied')->sum('amount');
        if ($total < $invoice->amount_paid + $credited) {
            throw \Illuminate\Validation\ValidationException::withMessages(['total' =>
                'The new total (' . number_format($total) . ') is less than what has already been paid or credited (' . number_format($invoice->amount_paid + $credited) . ').']);
        }

        $invoice->subtotal = $subtotal;
        $invoice->tax_amount = $taxAmount;
        $invoice->total = $total;
        if (! in_array($invoice->status, ['cancelled', 'waived', 'draft', 'proforma'], true)) {
            $invoice->status = $invoice->amount_paid + $credited >= $total && $total > 0 ? 'paid'
                : ($invoice->amount_paid > 0 ? 'partial' : (in_array($invoice->getOriginal('status'), ['sent'], true) ? 'sent' : 'pending'));
        }
        $invoice->save();

        $posted = \App\Models\Transaction::where('reference', "INV-{$invoice->id}")->exists();
        if ($posted && ($total !== $oldTotal || $invoice->wasChanged('tax_amount'))) {
            app(\App\Services\AccountingService::class)->reverseByReference("INV-{$invoice->id}");
            $financePosting->postInvoiceIssued($invoice);
        }
    }

    // Delivery note PDF: what was delivered, no prices, signature blocks.
    public function deliveryNote(Invoice $invoice, DocumentPdfService $pdfService)
    {
        return $pdfService->deliveryNotePdf($invoice);
    }

    // New Sale Notification (Clickhuduma): email the customer the invoice PDF.
    public function notify(Request $request, Invoice $invoice, DocumentPdfService $pdfService)
    {
        abort_if(! $request->user()->hasAccountantAuthority() && ! $request->user()->hasSalesEditAuthority(), 403,
            'You are not authorised to email invoices.');
        $data = $request->validate([
            'to'      => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:10000'],
        ]);

        $pdf = $pdfService->invoicePdf($invoice)->getContent();
        $from = $request->user();
        \Illuminate\Support\Facades\Mail::raw($data['message'], function ($m) use ($data, $invoice, $pdf, $from) {
            $m->to($data['to'])->subject($data['subject'])
                ->attachData($pdf, "{$invoice->invoice_number}.pdf", ['mime' => 'application/pdf']);
            if ($from?->email) {
                $m->replyTo($from->email, $from->name);
            }
        });

        activity()->performedOn($invoice)->causedBy($from)
            ->withProperties(['to' => $data['to'], 'subject' => $data['subject']])
            ->log("Emailed invoice {$invoice->invoice_number} to {$data['to']}");

        return response()->json(['message' => "Invoice emailed to {$data['to']}."]);
    }

    public function destroy(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        // Drafts and proformas never touched the accounts — an accountant
        // can remove them. A final sale still needs the Director.
        if (! $invoice->isFinal()) {
            abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to delete this.');
            $invoice->lineItems()->delete();
            $invoice->delete();

            return response()->json(null, 204);
        }
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
        abort_unless($invoice->isFinal(), 422, 'Finalise the sale before sending it.');
        abort_if(! $request->user()->hasAccountantAuthority(), 403, 'You are not authorised to send invoices.');
        if ($invoice->status !== 'pending') {
            return response()->json(['message' => 'Only pending invoices can be sent.'], 422);
        }

        $invoice->update(['status' => 'sent']);

        return response()->json(['data' => new InvoiceResource($invoice->load(['hospital', 'machine', 'salesOrder', 'lineItems', 'payments']))]);
    }

    public function cancel(Request $request, Invoice $invoice, FinancePostingService $financePosting)
    {
        abort_unless($invoice->isFinal(), 422, 'Delete the draft instead of cancelling it.');
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
        abort_unless($invoice->isFinal(), 422, 'Finalise the sale before recording a payment.');
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
