<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;

/**
 * Posts Sales-domain money events (invoice issued, payment received) to the
 * ledger via AccountingService. Centralized here so InvoiceController,
 * SalesOrderController::createInvoice(), and PosSaleService all post the
 * same way instead of each re-deriving the account-code mapping.
 *
 * Revenue simplification: the whole invoice total is credited to Equipment
 * Sales Revenue (4001). InvoiceLineItem has no FK back to the originating
 * InventoryItem/SalesOrderItem, so a genuine per-line equipment/consumables/
 * service revenue split isn't derivable yet — revisit once that linkage
 * exists. COGS is deliberately not posted here; P&L (Finance E) computes it
 * straight from source tables, matching how Invoice revenue recognition and
 * period reporting are kept independent throughout this module.
 */
class FinancePostingService
{
    private const PAYMENT_METHOD_ACCOUNT_CODE = [
        'cash'          => '1001',
        'bank_transfer' => '1002',
        'mobile_money'  => '1004',
        'cheque'        => '1002',
    ];

    // Expense.payment_mode uses a different enum ('bank' not 'bank_transfer', no 'cheque').
    private const EXPENSE_PAYMENT_MODE_ACCOUNT_CODE = [
        'cash'         => '1001',
        'bank'         => '1002',
        'mobile_money' => '1004',
    ];

    private const ACCOUNTS_RECEIVABLE_CODE = '1005';
    private const INVENTORY_CODE           = '1006';
    private const ACCOUNTS_PAYABLE_CODE    = '2001';
    private const VAT_PAYABLE_CODE         = '2002';
    private const DEFAULT_REVENUE_CODE     = '4001';
    private const DEFAULT_EXPENSE_CODE     = '5010'; // Miscellaneous Expense

    public function __construct(private AccountingService $accounting) {}

    // Dr Accounts Receivable (gross) / Cr Revenue (net) + Cr VAT Payable (output VAT) —
    // VAT collected on a sale is a liability owed to TRA, not revenue.
    public function postInvoiceIssued(Invoice $invoice): void
    {
        if ($invoice->total <= 0) {
            return;
        }

        $netRevenue = $invoice->total - $invoice->tax_amount;

        $entries = [
            ['account_id' => $this->accounting->accountIdByCode(self::ACCOUNTS_RECEIVABLE_CODE), 'type' => 'debit', 'amount' => $invoice->total],
        ];
        if ($netRevenue > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode(self::DEFAULT_REVENUE_CODE), 'type' => 'credit', 'amount' => $netRevenue];
        }
        if ($invoice->tax_amount > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode(self::VAT_PAYABLE_CODE), 'type' => 'credit', 'amount' => $invoice->tax_amount];
        }

        $this->accounting->recordTransaction($entries, "Invoice {$invoice->invoice_number}", "INV-{$invoice->id}");
    }

    // Dr Cash/Bank/Mobile Money / Cr Accounts Receivable — records cash received against an invoice.
    public function postPaymentReceived(Invoice $invoice, Payment $payment): void
    {
        if ($payment->amount <= 0) {
            return;
        }

        $paymentAccountCode = self::PAYMENT_METHOD_ACCOUNT_CODE[$payment->payment_method] ?? self::PAYMENT_METHOD_ACCOUNT_CODE['cash'];

        $this->accounting->recordTransaction([
            ['account_id' => $this->accounting->accountIdByCode($paymentAccountCode),           'type' => 'debit',  'amount' => $payment->amount],
            ['account_id' => $this->accounting->accountIdByCode(self::ACCOUNTS_RECEIVABLE_CODE), 'type' => 'credit', 'amount' => $payment->amount],
        ], "Payment {$payment->payment_number} — Invoice {$invoice->invoice_number}", "INV-{$invoice->id}-PAY-{$payment->id}");
    }

    // Reverses an invoice's revenue posting and every payment posted against it —
    // used when an invoice is cancelled or deleted, so the ledger doesn't drift.
    public function reverseInvoice(Invoice $invoice): void
    {
        foreach ($invoice->payments as $payment) {
            $this->accounting->reverseByReference("INV-{$invoice->id}-PAY-{$payment->id}");
        }
        $this->accounting->reverseByReference("INV-{$invoice->id}");
    }

    // Dr Expense category account (net) + Dr VAT Payable (input VAT, offsetting the
    // liability) / Cr Cash/Bank/Mobile Money (gross) — an expense is always paid
    // immediately, so issuing and paying are a single posting.
    public function postExpense(Expense $expense): void
    {
        $gross = $expense->amount + $expense->tax_amount;
        if ($gross <= 0) {
            return;
        }

        $expenseAccountCode = $expense->category?->account_id
            ? $expense->category->account->code
            : self::DEFAULT_EXPENSE_CODE;
        $paymentAccountCode = self::EXPENSE_PAYMENT_MODE_ACCOUNT_CODE[$expense->payment_mode] ?? self::EXPENSE_PAYMENT_MODE_ACCOUNT_CODE['cash'];

        $entries = [];
        if ($expense->amount > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode($expenseAccountCode), 'type' => 'debit', 'amount' => $expense->amount];
        }
        if ($expense->tax_amount > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode(self::VAT_PAYABLE_CODE), 'type' => 'debit', 'amount' => $expense->tax_amount];
        }
        $entries[] = ['account_id' => $this->accounting->accountIdByCode($paymentAccountCode), 'type' => 'credit', 'amount' => $gross];

        $this->accounting->recordTransaction($entries, "Expense: {$expense->name}", "EXP-{$expense->id}");
    }

    public function reverseExpense(Expense $expense): void
    {
        $this->accounting->reverseByReference("EXP-{$expense->id}");
    }

    // Dr Inventory (if tied to a Purchase Order) or the bill's expense category (net) +
    // Dr VAT Payable (input VAT) / Cr Accounts Payable (gross) — recognizes a liability
    // when a vendor bill is issued.
    public function postVendorBillIssued(VendorBill $bill): void
    {
        if ($bill->total <= 0) {
            return;
        }

        if ($bill->purchase_order_id) {
            $debitAccountCode = self::INVENTORY_CODE;
        } else {
            $debitAccountCode = $bill->category?->account_id
                ? $bill->category->account->code
                : self::DEFAULT_EXPENSE_CODE;
        }

        $net = $bill->total - $bill->tax_amount;

        $entries = [];
        if ($net > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode($debitAccountCode), 'type' => 'debit', 'amount' => $net];
        }
        if ($bill->tax_amount > 0) {
            $entries[] = ['account_id' => $this->accounting->accountIdByCode(self::VAT_PAYABLE_CODE), 'type' => 'debit', 'amount' => $bill->tax_amount];
        }
        $entries[] = ['account_id' => $this->accounting->accountIdByCode(self::ACCOUNTS_PAYABLE_CODE), 'type' => 'credit', 'amount' => $bill->total];

        $this->accounting->recordTransaction($entries, "Vendor Bill {$bill->bill_number}", "BILL-{$bill->id}");
    }

    // Dr Accounts Payable / Cr Cash/Bank/Mobile Money — records a payment made against a vendor bill.
    public function postVendorBillPayment(VendorBill $bill, VendorBillPayment $payment): void
    {
        if ($payment->amount <= 0) {
            return;
        }

        $paymentAccountCode = self::PAYMENT_METHOD_ACCOUNT_CODE[$payment->payment_method] ?? self::PAYMENT_METHOD_ACCOUNT_CODE['cash'];

        $this->accounting->recordTransaction([
            ['account_id' => $this->accounting->accountIdByCode(self::ACCOUNTS_PAYABLE_CODE), 'type' => 'debit',  'amount' => $payment->amount],
            ['account_id' => $this->accounting->accountIdByCode($paymentAccountCode),         'type' => 'credit', 'amount' => $payment->amount],
        ], "Payment {$payment->payment_number} — Bill {$bill->bill_number}", "BILL-{$bill->id}-PAY-{$payment->id}");
    }

    public function reverseVendorBill(VendorBill $bill): void
    {
        foreach ($bill->payments as $payment) {
            $this->accounting->reverseByReference("BILL-{$bill->id}-PAY-{$payment->id}");
        }
        $this->accounting->reverseByReference("BILL-{$bill->id}");
    }
}
