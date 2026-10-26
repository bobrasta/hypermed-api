<?php

namespace App\Services\Creditors;

use App\Models\ChartOfAccount;
use App\Models\CreditorAllocation;
use App\Models\CreditorOpeningBalance;
use App\Models\PerDiemRequest;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorFee;
use App\Services\AccountingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creditors in the books: supplier invoices, supplier payments, opening
 * balances, vendor fees and per diem, all posted through AccountingService.
 *
 * Money in a supplier's currency is handled as integer cents; TZS is whole
 * shillings. Each creditor posts to Trade Creditors — International (2024)
 * when its currency isn't TZS, else Trade Creditors — Local (2025).
 *
 * Exchange differences (IAS 21): an invoice is carried at its own rate; a
 * payment settling it at another rate posts the difference to Foreign
 * Exchange Loss (5059) / Gain (4005). Money paid before the invoice (an
 * advance) is carried at the payment's rate, and the part of a later invoice
 * it covers is booked at that same rate, so no difference arises (IFRIC 22).
 */
class CreditorService
{
    public const INTERNATIONAL = '2024';
    public const LOCAL = '2025';
    public const FX_GAIN = '4005';
    public const FX_LOSS = '5059';
    public const OPENING = '3006';
    public const INVENTORY = '1006';
    public const VAT_INPUT = '2002';
    public const MISC_EXPENSE = '5010';
    public const CLEARING = '5016';
    public const CARRIAGE_OUT = '5017';
    public const PER_DIEM = '5018';
    public const FALLBACK_BANK = '1002';

    private const METHOD_ACCOUNT = ['cash' => '1001', 'bank_transfer' => '1002', 'cheque' => '1002', 'mobile_money' => '1004'];

    public function __construct(private AccountingService $books) {}

    // ───────────────────────────────────────────────────────────── helpers

    public static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    public static function tzs(int $cents, $rate): int
    {
        return (int) round($cents * (float) $rate / 100);
    }

    public static function apCode(string $currency): string
    {
        return strtoupper($currency) === 'TZS' ? self::LOCAL : self::INTERNATIONAL;
    }

    public static function category(string $currency): string
    {
        return strtoupper($currency) === 'TZS' ? 'local' : 'international';
    }

    /** Accounts money can leave from: cash, mobile money and every bank. */
    public static function paymentAccounts(): Collection
    {
        return ChartOfAccount::with('category')->where('status', 'active')
            ->whereHas('category', fn ($q) => $q->where('type', 'asset'))
            ->where(fn ($q) => $q->whereIn('code', ['1001', '1002', '1004'])->orWhere('name', 'ilike', '%bank%'))
            ->orderBy('code')->get(['id', 'code', 'name', 'category_id']);
    }

    public static function assertPaymentAccount(?int $id): ChartOfAccount
    {
        $acc = $id ? self::paymentAccounts()->firstWhere('id', $id) : null;
        if (! $acc) {
            throw ValidationException::withMessages(['paid_from_account_id' => 'Pick the bank, cash or mobile-money account the money is paid from.']);
        }

        return $acc;
    }

    public function accountForMethod(?string $method): int
    {
        return $this->books->accountIdByCode(self::METHOD_ACCOUNT[$method] ?? self::FALLBACK_BANK);
    }

    private function leg(string $code, string $type, int $amount, ?string $desc = null): ?array
    {
        if ($amount === 0) {
            return null;
        }
        if ($amount < 0) {
            $type = $type === 'debit' ? 'credit' : 'debit';
            $amount = -$amount;
        }

        return array_filter(['account_id' => $this->books->accountIdByCode($code), 'type' => $type, 'amount' => $amount, 'description' => $desc]);
    }

    private function legById(int $accountId, string $type, int $amount): array
    {
        return ['account_id' => $accountId, 'type' => $type, 'amount' => $amount];
    }

    private function post(array $legs, string $description, string $reference, $date): void
    {
        $legs = array_values(array_filter($legs));
        $this->books->recordTransaction($legs, $description, $reference, $date ? (string) $date : null);
    }

    // ──────────────────────────────────────────────── open items (supplier)

    /** Credit items still owed, oldest first: [kind, model, date, open cents, rate]. */
    public function openCredits(string $type, int $creditorId): Collection
    {
        $items = collect();
        foreach (CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $creditorId)->where('amount', '>', 0)->get() as $o) {
            $open = self::cents($o->amount) - self::cents(CreditorAllocation::where('opening_owed_id', $o->id)->sum('amount'));
            if ($open > 0) {
                $items->push(['kind' => 'opening', 'model' => $o, 'date' => $o->as_at, 'open' => $open, 'rate' => (float) $o->exchange_rate]);
            }
        }
        // Vendors' own fees settle through Section 16, not through these payments.
        $bills = $type === 'supplier' ? VendorBill::where('supplier_id', $creditorId)->where('status', '!=', 'cancelled')->get() : collect();
        foreach ($bills as $b) {
            $open = self::cents($b->total) - self::cents(CreditorAllocation::where('vendor_bill_id', $b->id)->sum('amount'));
            if ($open > 0) {
                $items->push(['kind' => 'bill', 'model' => $b, 'date' => $b->issue_date, 'open' => $open, 'rate' => (float) $b->exchange_rate]);
            }
        }

        return $items->sortBy(fn ($i) => $i['date']->format('Y-m-d') . sprintf('%012d', $i['model']->id))->values();
    }

    /** Money paid ahead and not yet used up, oldest first. */
    public function openAdvances(string $type, int $creditorId): Collection
    {
        $items = collect();
        foreach (CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $creditorId)->where('amount', '<', 0)->get() as $o) {
            $open = -self::cents($o->amount) - self::cents(CreditorAllocation::where('opening_balance_id', $o->id)->sum('amount'));
            if ($open > 0) {
                $items->push(['kind' => 'opening', 'model' => $o, 'date' => $o->as_at, 'open' => $open, 'rate' => (float) $o->exchange_rate]);
            }
        }
        foreach (SupplierPayment::where($type === 'supplier' ? 'supplier_id' : 'vendor_id', $creditorId)->where('status', 'paid')->get() as $p) {
            $open = self::cents($p->amount) - self::cents(CreditorAllocation::where('supplier_payment_id', $p->id)->sum('amount'));
            if ($open > 0) {
                $items->push(['kind' => 'payment', 'model' => $p, 'date' => $p->payment_date, 'open' => $open, 'rate' => (float) $p->exchange_rate]);
            }
        }
        // Goods sent back: what their invoice didn't absorb is owed back to us.
        $returns = $type === 'supplier' ? PurchaseReturn::where('supplier_id', $creditorId)->where('posted', true)->get() : collect();
        foreach ($returns as $r) {
            $open = self::cents($r->total_amount) - self::cents(CreditorAllocation::where('purchase_return_id', $r->id)->sum('amount'));
            if ($open > 0) {
                $items->push(['kind' => 'return', 'model' => $r, 'date' => $r->return_date, 'open' => $open, 'rate' => (float) $r->exchange_rate]);
            }
        }

        return $items->sortBy(fn ($i) => $i['date']->format('Y-m-d') . sprintf('%012d', $i['model']->id))->values();
    }

    private function refreshBill(VendorBill $bill): void
    {
        $paid = CreditorAllocation::where('vendor_bill_id', $bill->id)->sum('amount');
        $paidCents = self::cents($paid);
        $status = $bill->status === 'cancelled' ? 'cancelled'
            : ($paidCents >= self::cents($bill->total) ? 'paid' : ($paidCents > 0 ? 'partial' : 'pending'));
        $bill->update(['amount_paid' => round($paidCents / 100, 2), 'status' => $status]);
    }

    public function refreshPurchaseOrder(?PurchaseOrder $po): void
    {
        if (! $po) {
            return;
        }
        $paid = SupplierPayment::where('purchase_order_id', $po->id)->where('status', 'paid')->get();
        $amount = strtoupper((string) $po->currency) === 'TZS'
            ? $paid->sum(fn ($p) => strtoupper($p->currency) === 'TZS' ? self::cents($p->amount) / 100 : $p->amount_tzs)
            : $paid->sum(fn ($p) => (float) $p->amount);
        $amount = (int) round($amount);
        // Goods sent back aren't paid for.
        $payable = (int) $po->total_amount - (int) PurchaseReturn::where('purchase_order_id', $po->id)->sum('total_amount');
        $po->update([
            'amount_paid' => $amount,
            'payment_status' => $amount <= 0 && $payable > 0 ? 'unpaid' : ($amount >= $payable ? 'paid' : 'partial'),
        ]);
    }

    // ───────────────────────────────────────────────── supplier invoices

    /** Posts a new invoice: uses earlier advances first (at their rate), the rest at the invoice rate. */
    public function postBill(VendorBill $bill): void
    {
        DB::transaction(function () use ($bill) {
            $total = self::cents($bill->total);
            $remaining = $total;
            $tzs = 0;
            foreach ($this->openAdvances('supplier', $bill->supplier_id) as $adv) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, $adv['open']);
                CreditorAllocation::create([
                    match ($adv['kind']) { 'payment' => 'supplier_payment_id', 'return' => 'purchase_return_id', default => 'opening_balance_id' } => $adv['model']->id,
                    'vendor_bill_id' => $bill->id, 'amount' => $take / 100, 'kind' => 'advance',
                ]);
                $tzs += self::tzs($take, $adv['rate']);
                $remaining -= $take;
            }
            $tzs += self::tzs($remaining, $bill->exchange_rate);
            $bill->update(['total_tzs' => $tzs]);
            $this->refreshBill($bill);

            $taxTzs = $total > 0 ? (int) round($tzs * self::cents($bill->tax_amount) / $total) : 0;
            $debitCode = $bill->purchase_order_id ? self::INVENTORY
                : ($bill->category?->account?->code ?? self::MISC_EXPENSE);
            $supplier = $bill->supplier;
            $label = 'Invoice ' . ($bill->supplier_reference ?: $bill->bill_number) . ' — ' . $supplier->name;
            if ($tzs > 0) {
                $this->post([
                    $this->leg($debitCode, 'debit', $tzs - $taxTzs),
                    $this->leg(self::VAT_INPUT, 'debit', $taxTzs),
                    $this->leg(self::apCode($bill->currency), 'credit', $tzs),
                ], $label, "BILL-{$bill->id}", $bill->issue_date?->toDateString());
            }
            $this->refreshPurchaseOrder($bill->purchaseOrder);
        });
    }

    public function reverseBill(VendorBill $bill): void
    {
        $paidAgainst = CreditorAllocation::where('vendor_bill_id', $bill->id)->where('kind', 'payment')->exists();
        abort_if($paidAgainst, 422, 'This invoice has payments against it — it can\'t be cancelled.');
        DB::transaction(function () use ($bill) {
            CreditorAllocation::where('vendor_bill_id', $bill->id)->delete(); // advances it used become free again
            $this->books->reverseByReference("BILL-{$bill->id}");
        });
    }

    // ───────────────────────────────────────────────── purchase returns

    /**
     * Goods sent back to the supplier. Only an order with a supplier invoice
     * on the books carries the goods in Trade Creditors and Inventory, so
     * only then does the return post (Dr Trade Creditors / Cr Inventory, at
     * the invoice's rate) and settle that invoice like a credit note; any
     * excess is owed back to us and used against their next invoice. An
     * order with no invoice yet just returns the stock — the supplier's
     * invoice then comes in for what was kept.
     */
    public function postPurchaseReturn(PurchaseReturn $return): void
    {
        $po = $return->purchaseOrder;
        $currency = strtoupper($po->supplier?->currency ?: 'TZS');
        $bills = VendorBill::where('purchase_order_id', $po->id)->where('status', '!=', 'cancelled')
            ->get()->filter(fn ($b) => strtoupper($b->currency) === $currency)->sortBy('issue_date')->values();
        if ($bills->isEmpty() || strtoupper((string) $po->currency) !== $currency || $return->total_amount <= 0) {
            return;
        }

        DB::transaction(function () use ($return, $bills, $currency, $po) {
            $rate = (float) $bills->first()->exchange_rate;
            $remaining = self::cents($return->total_amount);
            foreach ($bills as $bill) {
                $open = self::cents($bill->total) - self::cents(CreditorAllocation::where('vendor_bill_id', $bill->id)->sum('amount'));
                $take = min($remaining, max($open, 0));
                if ($take > 0) {
                    CreditorAllocation::create([
                        'purchase_return_id' => $return->id, 'vendor_bill_id' => $bill->id,
                        'amount' => $take / 100, 'kind' => 'return',
                    ]);
                    $remaining -= $take;
                    $this->refreshBill($bill);
                }
            }
            $tzs = self::tzs(self::cents($return->total_amount), $rate);
            $this->post([
                $this->leg(self::apCode($currency), 'debit', $tzs),
                $this->leg(self::INVENTORY, 'credit', $tzs),
            ], "Purchase return {$return->return_number} — {$po->supplier->name} ({$po->po_number})",
                "PRET-{$return->id}", $return->return_date?->toDateString());
            $return->update(['posted' => true, 'exchange_rate' => $rate, 'total_tzs' => $tzs]);
        });
    }

    // ───────────────────────────────────────────────── supplier payments

    /**
     * Director's final approval: matches the payment to what's owed (the
     * chosen invoice first, then oldest first), posts it, and records any
     * exchange difference. Whatever isn't matched stays as an advance.
     */
    public function approvePayment(SupplierPayment $payment, User $approver): SupplierPayment
    {
        abort_if($payment->status !== 'pending_director', 422, 'Only payments awaiting the Director can be approved.');
        abort_if($payment->initiated_by === $approver->id, 403, 'The same person cannot both initiate a payment and approve it.');

        return DB::transaction(function () use ($payment, $approver) {
            $payment = SupplierPayment::whereKey($payment->id)->lockForUpdate()->first();
            $remaining = self::cents($payment->amount);
            $rate = (float) $payment->exchange_rate;
            $apTzs = 0;
            $credits = $this->openCredits($payment->creditorType(), $payment->creditorId());
            if ($payment->vendor_bill_id) {
                $credits = $credits->sortBy(fn ($c) => $c['kind'] === 'bill' && $c['model']->id === $payment->vendor_bill_id ? 0 : 1)->values();
            }
            $touched = [];
            foreach ($credits as $c) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min($remaining, $c['open']);
                CreditorAllocation::create([
                    'supplier_payment_id' => $payment->id,
                    $c['kind'] === 'bill' ? 'vendor_bill_id' : 'opening_owed_id' => $c['model']->id,
                    'amount' => $take / 100, 'kind' => 'payment',
                ]);
                $apTzs += self::tzs($take, $c['rate']);
                $remaining -= $take;
                if ($c['kind'] === 'bill') {
                    $touched[] = $c['model'];
                }
            }
            $apTzs += self::tzs($remaining, $rate); // advance, carried at this payment's rate
            $bankTzs = (int) $payment->amount_tzs;
            $fx = $bankTzs - $apTzs; // + we paid more shillings than carried: loss

            $label = "Payment {$payment->payment_number} — {$payment->creditorName()}" . ($payment->reference ? " ({$payment->reference})" : '');
            $this->post([
                $this->leg(self::apCode($payment->currency), 'debit', $apTzs),
                $fx > 0 ? $this->leg(self::FX_LOSS, 'debit', $fx, "Exchange loss — {$payment->payment_number}") : null,
                $fx < 0 ? $this->leg(self::FX_GAIN, 'credit', -$fx, "Exchange gain — {$payment->payment_number}") : null,
                $this->legById($payment->paid_from_account_id, 'credit', $bankTzs),
            ], $label, "SPAY-{$payment->id}", $payment->payment_date?->toDateString());

            $payment->update([
                'status' => 'paid', 'approved_by' => $approver->id, 'approved_at' => now(), 'fx_difference_tzs' => $fx,
            ]);
            foreach ($touched as $bill) {
                $this->refreshBill($bill);
            }
            $this->refreshPurchaseOrder($payment->purchaseOrder);

            return $payment->fresh();
        });
    }

    // ─────────────────────────────────────────────────── opening balances

    public function postOpening(CreditorOpeningBalance $o): void
    {
        $name = $o->creditor_type === 'supplier' ? Supplier::find($o->creditor_id)?->name : Vendor::find($o->creditor_id)?->name;
        $this->post([
            $this->leg(self::OPENING, 'debit', $o->amount_tzs),
            $this->leg(self::apCode($o->currency), 'credit', $o->amount_tzs),
        ], "Opening balance — {$name}", "CROB-{$o->id}", $o->as_at?->toDateString());
    }

    public function reverseOpening(CreditorOpeningBalance $o): void
    {
        $used = CreditorAllocation::where('opening_owed_id', $o->id)->orWhere('opening_balance_id', $o->id)->exists();
        abort_if($used, 422, 'Payments or invoices have already been matched to this opening balance — it can\'t be changed.');
        $this->books->reverseByReference("CROB-{$o->id}");
    }

    // ─────────────────────────────────────────────── vendors (Section 16)

    /** A recorded vendor fee: clearing/courier → Clearing and Forwarding; a delivery job → Carriage Outwards. */
    public function postVendorFee(VendorFee $fee): void
    {
        if ($this->posted("VFEE-{$fee->id}")) {
            return;
        }
        $amount = (int) $fee->billed_amount;
        $this->post([
            $this->leg($fee->delivery_job_id ? self::CARRIAGE_OUT : self::CLEARING, 'debit', $amount),
            $this->leg(self::LOCAL, 'credit', $amount),
        ], "Vendor fee — {$fee->vendor?->name}: {$fee->description}", "VFEE-{$fee->id}", $fee->created_at?->toDateString());
    }

    public function postVendorFeePaid(VendorFee $fee): void
    {
        $this->postVendorFee($fee); // fees recorded before this went live
        $amount = (int) $fee->billed_amount;
        $from = $fee->paid_from_account_id ?: $this->books->accountIdByCode(self::FALLBACK_BANK);
        $this->post([
            $this->leg(self::LOCAL, 'debit', $amount),
            $this->legById($from, 'credit', $amount),
        ], "Vendor fee paid — {$fee->vendor?->name}" . ($fee->payment_reference ? " ({$fee->payment_reference})" : ''),
            "VFEE-{$fee->id}-PAY", ($fee->paid_at ?? now())->toDateString());
    }

    public function reverseVendorFee(VendorFee $fee): void
    {
        $this->books->reverseByReference("VFEE-{$fee->id}");
    }

    // ───────────────────────────────────────────────────────── per diem

    public function postPerDiemPaid(PerDiemRequest $pd): void
    {
        $amount = (int) $pd->amount;
        if ($amount <= 0 || $this->posted("PDM-{$pd->id}")) {
            return;
        }
        $from = $pd->paid_from_account_id ?: $this->accountForMethod($pd->payment_method);
        $this->post([
            $this->leg(self::PER_DIEM, 'debit', $amount),
            $this->legById($from, 'credit', $amount),
        ], "Per diem — {$pd->staff_name_snapshot} ({$pd->destination})", "PDM-{$pd->id}", ($pd->paid_at ?? now())->toDateString());
    }

    private function posted(string $reference): bool
    {
        return \App\Models\Transaction::where('reference', $reference)->exists();
    }
}
