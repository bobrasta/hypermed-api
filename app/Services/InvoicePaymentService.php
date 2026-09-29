<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

// One place that records a payment against an invoice: creates the
// Payment, updates amount_paid/status and posts it to the ledger. Used by
// "Record payment", the deposit taken when an invoice is created, and the
// customer-level "Pay due" that spreads one payment over several invoices.
// Callers wrap it in their own DB transaction.
class InvoicePaymentService
{
    public function __construct(
        private FinancePostingService $financePosting,
        private DocumentNumberService $documentNumbers,
    ) {}

    /**
     * @param array{amount:int, payment_method:string, paid_at:string, reference?:?string, notes?:?string} $data
     */
    public function apply(Invoice $invoice, array $data, User $by): Payment
    {
        if (in_array($invoice->status, ['paid', 'cancelled', 'waived'], true)) {
            throw ValidationException::withMessages(['amount' => "Cannot record payment on a {$invoice->status} invoice."]);
        }
        $balance = $invoice->balance_due;
        if ($data['amount'] > $balance) {
            throw ValidationException::withMessages([
                'amount' => 'Payment of TSh ' . number_format($data['amount']) . " is more than invoice {$invoice->invoice_number}'s balance of TSh " . number_format($balance) . '.',
            ]);
        }

        $payment = Payment::create([
            'payment_number' => $this->documentNumbers->next('payment'),
            'invoice_id'     => $invoice->id,
            'amount'         => $data['amount'],
            'payment_method' => $data['payment_method'],
            'reference'      => $data['reference'] ?? null,
            'paid_at'        => $data['paid_at'],
            'notes'          => $data['notes'] ?? null,
            'recorded_by'    => $by->id,
        ]);

        $totalPaid = (int) $invoice->payments()->sum('amount');
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

        $this->financePosting->postPaymentReceived($invoice, $payment);

        return $payment;
    }
}
