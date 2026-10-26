<?php

namespace App\Services\Creditors;

use App\Models\CreditorOpeningBalance;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorFee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The creditors ledger, in the accounts team's own layout
 * (CREDITOR LEDGERS DRILLAS.xlsx): per creditor, credits (their invoices)
 * against debits (our payments), the running balance in the creditor's
 * currency, and TZS at each line's exchange rate. Built from the records
 * themselves (opening balance, invoices, approved payments, vendor fees), so
 * every line links back to its source.
 */
class CreditorLedgerService
{
    /** Every supplier and vendor that can carry a balance. */
    public function creditors(?string $category = null, ?string $search = null): Collection
    {
        $rows = collect();
        foreach (Supplier::orderBy('name')->get() as $s) {
            $rows->push($this->creditorHeader('supplier', $s));
        }
        foreach (Vendor::orderBy('name')->get() as $v) {
            $rows->push($this->creditorHeader('vendor', $v));
        }
        if ($category) {
            $rows = $rows->where('category', $category);
        }
        if ($search) {
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['name']), mb_strtolower($search)));
        }

        return $rows->values();
    }

    public function find(string $type, int $id): array
    {
        $model = $type === 'supplier' ? Supplier::findOrFail($id) : Vendor::findOrFail($id);

        return $this->creditorHeader($type, $model);
    }

    private function creditorHeader(string $type, $m): array
    {
        $currency = $type === 'supplier' ? strtoupper($m->currency ?: 'TZS') : 'TZS';

        return [
            'type' => $type,
            'id' => $m->id,
            'name' => $m->name,
            'kind' => $type === 'supplier' ? ($m->type ?: 'supplier') : ($m->type ?: 'vendor'),
            'category' => CreditorService::category($currency),
            'currency' => $currency,
            'country' => $type === 'supplier' ? $m->country : 'Tanzania',
            'contact_person' => $m->contact_name,
            'phone' => $m->contact_phone,
            'email' => $m->contact_email,
            'is_active' => (bool) $m->is_active,
        ];
    }

    /**
     * Every posted line for a creditor, oldest first. Amounts in the
     * creditor's currency; *_tzs at the line's own rate.
     */
    public function entries(string $type, int $id): Collection
    {
        $rows = collect();
        $add = function (array $r) use ($rows) {
            $r['debit'] = round($r['debit'] ?? 0, 2);
            $r['credit'] = round($r['credit'] ?? 0, 2);
            $r['rate'] = (float) ($r['rate'] ?? 1);
            $r['debit_tzs'] = $r['debit_tzs'] ?? (int) round($r['debit'] * $r['rate']);
            $r['credit_tzs'] = $r['credit_tzs'] ?? (int) round($r['credit'] * $r['rate']);
            $rows->push($r);
        };

        $opening = CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $id)->first();
        if ($opening) {
            $amt = (float) $opening->amount;
            $add([
                'date' => $opening->as_at->toDateString(), 'seq' => 0, 'narration' => 'OPENING BALANCE', 'ref' => null,
                'credit' => max($amt, 0), 'debit' => max(-$amt, 0), 'rate' => $opening->exchange_rate,
                'source' => ['type' => 'creditor_opening', 'id' => $opening->id],
            ]);
        }

        if ($type === 'supplier') {
            $currency = strtoupper(Supplier::whereKey($id)->value('currency') ?: 'TZS');
            // An invoice in another currency than its supplier's predates currency
            // support; it stays off the ledger until its real currency/rate is set.
            $bills = VendorBill::with('purchaseOrder:id,po_number')->where('supplier_id', $id)->where('status', '!=', 'cancelled')
                ->get()->filter(fn ($b) => strtoupper($b->currency) === $currency);
            foreach ($bills as $b) {
                $add([
                    'date' => $b->issue_date->toDateString(), 'seq' => 1,
                    'narration' => 'INVOICE' . ($b->purchaseOrder ? ' — ' . $b->purchaseOrder->po_number : '') . ($b->notes ? ' — ' . $b->notes : ''),
                    'ref' => $b->supplier_reference ?: $b->bill_number,
                    'credit' => (float) $b->total, 'rate' => $b->exchange_rate,
                    'credit_tzs' => (int) round((float) $b->total * (float) $b->exchange_rate),
                    'source' => ['type' => 'vendor_bill', 'id' => $b->id],
                ]);
            }
            // Goods sent back against an invoiced order (posted returns only).
            foreach (PurchaseReturn::with('purchaseOrder:id,po_number')->where('supplier_id', $id)->where('posted', true)->get() as $r) {
                $add([
                    'date' => $r->return_date->toDateString(), 'seq' => 2,
                    'narration' => 'PURCHASE RETURN — ' . $r->purchaseOrder?->po_number . ($r->reason ? ' — ' . $r->reason : ''),
                    'ref' => $r->return_number, 'debit' => (float) $r->total_amount, 'rate' => $r->exchange_rate,
                    'debit_tzs' => (int) $r->total_tzs,
                    'source' => ['type' => 'purchase_return', 'id' => $r->id],
                ]);
            }
        } else {
            foreach (VendorFee::where('vendor_id', $id)->where('status', '!=', 'rejected')->get() as $f) {
                $add([
                    'date' => $f->created_at->toDateString(), 'seq' => 1, 'narration' => 'INVOICE — ' . $f->description,
                    'ref' => 'FEE-' . $f->id, 'credit' => (int) $f->billed_amount, 'rate' => 1,
                    'source' => ['type' => 'vendor_fee', 'id' => $f->id],
                ]);
                if ($f->status === 'paid' && $f->paid_at) {
                    $f->loadMissing('paidFrom:id,name');
                    $add([
                        'date' => $f->paid_at->toDateString(), 'seq' => 2,
                        'narration' => strtoupper($f->paidFrom?->name ?? 'BANK') . ' — fee ' . $f->id,
                        'ref' => $f->payment_reference, 'debit' => (int) $f->billed_amount, 'rate' => 1,
                        'source' => ['type' => 'vendor_fee', 'id' => $f->id],
                    ]);
                }
            }
        }

        $payments = SupplierPayment::with(['paidFrom:id,name', 'purchaseOrder:id,po_number'])
            ->where($type === 'supplier' ? 'supplier_id' : 'vendor_id', $id)->where('status', 'paid')->get();
        foreach ($payments as $p) {
            $add([
                'date' => $p->payment_date->toDateString(), 'seq' => 2,
                'narration' => strtoupper($p->paidFrom?->name ?? 'BANK') . ($p->purchaseOrder ? ' — ' . $p->purchaseOrder->po_number : ''),
                'ref' => $p->reference ?: $p->payment_number, 'debit' => (float) $p->amount, 'rate' => $p->exchange_rate,
                'debit_tzs' => (int) $p->amount_tzs,
                'source' => ['type' => 'supplier_payment', 'id' => $p->id],
            ]);
        }

        return $rows->sortBy(fn ($r) => $r['date'] . $r['seq'] . sprintf('%012d', $r['source']['id']))->values();
    }

    /**
     * The statement for [$from, $to] (dates as Y-m-d; null = all): opening
     * b/f, the lines with running balances, totals. balance_tzs follows the
     * sheet (running balance × that line's rate); book_balance_tzs is what
     * Trade Creditors carries (invoices at their rate, payments at the rate
     * of what they settled).
     */
    public function statement(string $type, int $id, ?string $from = null, ?string $to = null): array
    {
        $to ??= now()->toDateString();
        $all = $this->entries($type, $id)->filter(fn ($r) => $r['date'] <= $to);
        $before = $from ? $all->filter(fn ($r) => $r['date'] < $from) : collect();
        $in = $from ? $all->filter(fn ($r) => $r['date'] >= $from) : $all;

        $openingFc = round($before->sum('credit') - $before->sum('debit'), 2);
        $balance = $openingFc;
        $rows = [];
        $lastRate = $before->last()['rate'] ?? 1;
        foreach ($in as $r) {
            $balance = round($balance + $r['credit'] - $r['debit'], 2);
            $lastRate = $r['rate'];
            $rows[] = $r + ['balance' => $balance, 'balance_tzs' => (int) round($balance * $r['rate'])];
        }

        return [
            'creditor' => $this->find($type, $id),
            'period' => ['from' => $from, 'to' => $to],
            'opening_balance' => $openingFc,
            'opening_balance_tzs' => (int) round($openingFc * ($before->last()['rate'] ?? 1)),
            'entries' => $rows,
            'total_credit' => round($in->sum('credit'), 2),
            'total_debit' => round($in->sum('debit'), 2),
            'total_credit_tzs' => (int) $in->sum('credit_tzs'),
            'total_debit_tzs' => (int) $in->sum('debit_tzs'),
            'closing_balance' => $balance,
            'closing_balance_tzs' => (int) round($balance * $lastRate),
            'book_balance_tzs' => $this->bookBalanceTzs($type, $id, $to),
        ];
    }

    /** What Trade Creditors carries for this creditor at $to (TZS). */
    public function bookBalanceTzs(string $type, int $id, ?string $to = null): int
    {
        $to ??= now()->toDateString();
        $o = CreditorOpeningBalance::where('creditor_type', $type)->where('creditor_id', $id)->whereDate('as_at', '<=', $to)->first();
        $sum = $o ? (int) $o->amount_tzs : 0;
        if ($type === 'supplier') {
            $currency = strtoupper(Supplier::whereKey($id)->value('currency') ?: 'TZS');
            $sum += (int) VendorBill::where('supplier_id', $id)->where('status', '!=', 'cancelled')->where('currency', $currency)
                ->whereDate('issue_date', '<=', $to)->sum('total_tzs');
            $sum -= (int) PurchaseReturn::where('supplier_id', $id)->where('posted', true)->whereDate('return_date', '<=', $to)->sum('total_tzs');
        } else {
            $fees = VendorFee::where('vendor_id', $id)->where('status', '!=', 'rejected');
            $sum += (int) (clone $fees)->whereDate('created_at', '<=', $to)->sum('billed_amount');
            $sum -= (int) (clone $fees)->where('status', 'paid')->whereDate('paid_at', '<=', $to)->sum('billed_amount');
        }
        // A payment takes off what it carried: the shillings paid less the exchange difference.
        $paid = SupplierPayment::where($type === 'supplier' ? 'supplier_id' : 'vendor_id', $id)->where('status', 'paid')->whereDate('payment_date', '<=', $to)->get();
        $sum -= (int) $paid->sum(fn ($p) => $p->amount_tzs - $p->fx_difference_tzs);

        return $sum;
    }

    /** Balance of every creditor as at $asAt, for the list screen. */
    public function balances(?string $asAt = null, ?string $category = null, ?string $search = null): Collection
    {
        $asAt ??= now()->toDateString();

        return $this->creditors($category, $search)->map(function ($c) use ($asAt) {
            $entries = $this->entries($c['type'], $c['id'])->filter(fn ($r) => $r['date'] <= $asAt);
            $balance = round($entries->sum('credit') - $entries->sum('debit'), 2);
            $last = $entries->last();
            $pendingQ = SupplierPayment::where($c['type'] === 'supplier' ? 'supplier_id' : 'vendor_id', $c['id'])->where('status', 'pending_director');

            return $c + [
                'balance' => $balance,
                'balance_tzs' => (int) round($balance * ($last['rate'] ?? 1)),
                'book_balance_tzs' => $this->bookBalanceTzs($c['type'], $c['id'], $asAt),
                'total_credit' => round($entries->sum('credit'), 2),
                'total_debit' => round($entries->sum('debit'), 2),
                'last_activity' => $last['date'] ?? null,
                'lines' => $entries->count(),
                'pending_payments' => (clone $pendingQ)->count(),
                'pending_amount' => round((float) (clone $pendingQ)->sum('amount'), 2),
            ];
        })->values();
    }

    public static function date(?string $d): ?string
    {
        return $d ? Carbon::parse($d)->format('d.m.Y') : null;
    }
}
