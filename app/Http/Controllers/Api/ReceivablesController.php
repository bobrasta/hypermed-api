<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use App\Models\Hospital;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\EffectivePermissionResolver;
use App\Services\InvoicePaymentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Credit sales / hire purchase tracking, modelled on Clickhuduma: a credit
// sale is simply an invoice that isn't fully paid (with a payment term that
// sets its due date); instalments are payments recorded against it as they
// come in. This controller gives the three views Clickhuduma had on top of
// that — who owes what (its Customer report), a per-customer statement (its
// contact ledger) and a lump "Pay due" that settles oldest invoices first.
//
// A customer is the client record (hospital_id) when the invoice has one,
// otherwise the typed client name (walk-in / not in the directory).
class ReceivablesController extends Controller
{
    private const OPEN = ['pending', 'sent', 'partial', 'overdue'];

    private function assertReadAccess(Request $request): void
    {
        abort_unless(
            app(EffectivePermissionResolver::class)->can($request->user(), 'screens.finance')
                || $request->user()->hasAccountantAuthority(),
            403,
            'You are not authorised to view receivables.'
        );
    }

    private static function keyFor(?int $hospitalId, ?string $clientName): string
    {
        return $hospitalId ? "h:{$hospitalId}" : 'n:' . mb_strtolower(trim((string) $clientName));
    }

    /** Invoices of one customer, identified by hospital_id or client_name. */
    private function customerInvoices(Request $request): Builder
    {
        $data = $request->validate([
            'hospital_id' => ['nullable', 'integer', 'required_without:client_name'],
            'client_name' => ['nullable', 'string', 'required_without:hospital_id'],
        ]);

        return Invoice::query()->when(
            ! empty($data['hospital_id']),
            fn ($q) => $q->where('hospital_id', $data['hospital_id']),
            fn ($q) => $q->whereNull('hospital_id')
                ->whereRaw('lower(trim(client_name)) = ?', [mb_strtolower(trim($data['client_name']))]),
        );
    }

    /** Open invoices with their live balance (total − paid − applied credit notes). */
    private function openInvoices(?Builder $scope = null): Collection
    {
        $q = ($scope ?? Invoice::query())
            ->whereIn('status', self::OPEN)
            ->with('hospital:id,name,tin,contact_phone')
            ->withSum(['creditNotes as credited' => fn ($c) => $c->where('status', 'applied')], 'amount')
            ->withCount('payments')
            ->withMax('payments as last_payment_at', 'paid_at');

        return $q->get()->map(function (Invoice $i) {
            $i->balance = max(0, (int) $i->total - (int) $i->amount_paid - (int) ($i->credited ?? 0));
            return $i;
        })->filter(fn ($i) => $i->balance > 0)->values();
    }

    private static function daysOverdue(Invoice $i, Carbon $today): int
    {
        return $i->due_date && $i->due_date->lt($today) ? $i->due_date->diffInDays($today) : 0;
    }

    private static function invoiceRow(Invoice $i, Carbon $today): array
    {
        return [
            'id'              => $i->id,
            'invoice_number'  => $i->invoice_number,
            'issue_date'      => $i->issue_date?->toDateString(),
            'due_date'        => $i->due_date?->toDateString(),
            'pay_term'        => $i->pay_term_number !== null ? "{$i->pay_term_number} {$i->pay_term_type}" : null,
            'total'           => (int) $i->total,
            'paid'            => (int) $i->amount_paid,
            'balance'         => $i->balance,
            'status'          => $i->status,
            'days_overdue'    => self::daysOverdue($i, $today),
            'payments_count'  => (int) $i->payments_count,
            'last_payment_at' => $i->last_payment_at ? Carbon::parse($i->last_payment_at)->toDateString() : null,
        ];
    }

    // GET /receivables — every customer who owes, plus the headline numbers.
    public function index(Request $request)
    {
        $this->assertReadAccess($request);
        $today = Carbon::today();
        $from = $request->date('date_from');
        $to = $request->date('date_to');

        $open = $this->openInvoices();
        $customers = $open->groupBy(fn ($i) => self::keyFor($i->hospital_id, $i->client_name))
            ->map(function (Collection $inv, string $key) use ($today) {
                $first = $inv->first();
                $buckets = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90_plus' => 0];
                foreach ($inv as $i) {
                    $d = self::daysOverdue($i, $today);
                    $buckets[match (true) { $d === 0 => 'current', $d <= 30 => 'd1_30', $d <= 60 => 'd31_60', $d <= 90 => 'd61_90', default => 'd90_plus' }] += $i->balance;
                }
                $last = $inv->pluck('last_payment_at')->filter()->max();

                return [
                    'key'              => $key,
                    'hospital_id'      => $first->hospital_id,
                    'client_name'      => $first->hospital?->name ?? $first->client_name,
                    'tin'              => $first->hospital?->tin ?? $first->client_tin,
                    'phone'            => $first->hospital?->contact_phone ?? $first->client_contact,
                    'open_invoices'    => $inv->count(),
                    'billed'           => (int) $inv->sum('total'),
                    'paid'             => (int) $inv->sum('amount_paid'),
                    'balance'          => (int) $inv->sum('balance'),
                    'overdue_balance'  => $buckets['d1_30'] + $buckets['d31_60'] + $buckets['d61_90'] + $buckets['d90_plus'],
                    'aging'            => $buckets,
                    'oldest_due_date'  => $inv->pluck('due_date')->filter()->min()?->toDateString(),
                    'max_days_overdue' => $inv->map(fn ($i) => self::daysOverdue($i, $today))->max(),
                    'no_payment_invoices' => $inv->where('amount_paid', 0)->count(),
                    'last_payment_at'  => $last ? Carbon::parse($last)->toDateString() : null,
                ];
            })->values();

        if ($q = trim((string) $request->input('search'))) {
            $needle = mb_strtolower($q);
            $customers = $customers->filter(fn ($c) => str_contains(mb_strtolower((string) $c['client_name']), $needle)
                || str_contains((string) $c['tin'], $q))->values();
        }
        $customers = $customers->sortByDesc('balance')->values();

        // Collections vs billing in the chosen period (gwbook-style KPIs).
        $collected = Payment::query()
            ->when($from, fn ($q) => $q->whereDate('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('paid_at', '<=', $to))
            ->sum('amount');
        $billed = Invoice::query()->whereNotIn('status', ['cancelled', 'waived'])
            ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
            ->sum('total');

        return response()->json([
            'summary' => [
                'outstanding'         => (int) $open->sum('balance'),
                'overdue'             => (int) $customers->sum('overdue_balance'),
                'customers_owing'     => $customers->count(),
                'open_invoices'       => $open->count(),
                'no_payment_invoices' => $open->where('amount_paid', 0)->count(),
                'collected'           => (int) $collected,
                'billed'              => (int) $billed,
                'collection_rate'     => $billed > 0 ? round($collected / $billed * 100, 1) : null,
            ],
            'data' => $customers,
        ]);
    }

    // GET /receivables/statement?hospital_id=|client_name=&date_from&date_to
    // Clickhuduma's contact ledger: opening balance, then every invoice
    // (debit), payment and applied credit note (credit) by date with a
    // running balance, plus the customer's open invoices with instalments.
    public function statement(Request $request)
    {
        $this->assertReadAccess($request);
        $today = Carbon::today();
        $from = $request->date('date_from');
        $to = $request->date('date_to');

        $invoices = $this->customerInvoices($request)
            ->whereNotIn('status', ['cancelled', 'waived'])
            ->with(['payments', 'hospital:id,name,tin,contact_phone,contact_email,address'])
            ->orderBy('issue_date')->get();
        abort_if($invoices->isEmpty(), 404, 'No invoices for this customer.');

        $credits = CreditNote::whereIn('invoice_id', $invoices->pluck('id'))->where('status', 'applied')->get();

        $entries = collect();
        foreach ($invoices as $i) {
            $entries->push(['date' => $i->issue_date?->toDateString(), 'type' => 'invoice', 'ref' => $i->invoice_number,
                'invoice_id' => $i->id, 'description' => $i->pay_term_number !== null ? "Invoice · terms {$i->pay_term_number} {$i->pay_term_type}" : 'Invoice',
                'debit' => (int) $i->total, 'credit' => 0]);
            foreach ($i->payments as $n => $p) {
                $entries->push(['date' => $p->paid_at?->toDateString(), 'type' => 'payment', 'ref' => $p->payment_number,
                    'invoice_id' => $i->id, 'description' => 'Payment ' . ($n + 1) . " for {$i->invoice_number} · " . str_replace('_', ' ', $p->payment_method)
                        . ($p->reference ? " · {$p->reference}" : ''), 'debit' => 0, 'credit' => (int) $p->amount]);
            }
        }
        foreach ($credits as $c) {
            $inv = $invoices->firstWhere('id', $c->invoice_id);
            $entries->push(['date' => ($c->applied_at ?? $c->updated_at)?->toDateString(), 'type' => 'credit_note', 'ref' => $c->credit_note_number ?? "CN-{$c->id}",
                'invoice_id' => $c->invoice_id, 'description' => "Credit note on {$inv?->invoice_number}", 'debit' => 0, 'credit' => (int) $c->amount]);
        }
        $entries = $entries->sortBy([['date', 'asc'], [fn ($a, $b) => $a['type'] === 'invoice' ? -1 : 1]])->values();

        $before = $from ? $entries->filter(fn ($e) => $e['date'] < $from->toDateString()) : collect();
        $opening = (int) ($before->sum('debit') - $before->sum('credit'));
        $inRange = $entries->filter(fn ($e) => (! $from || $e['date'] >= $from->toDateString()) && (! $to || $e['date'] <= $to->toDateString()))->values();

        $running = $opening;
        $inRange = $inRange->map(function ($e) use (&$running) {
            $running += $e['debit'] - $e['credit'];
            return $e + ['balance' => $running];
        });

        $first = $invoices->first();
        $open = $this->openInvoices($this->customerInvoices($request))->sortBy('issue_date')->values();

        return response()->json([
            'customer' => [
                'hospital_id' => $first->hospital_id,
                'client_name' => $first->hospital?->name ?? $first->client_name,
                'tin'         => $first->hospital?->tin ?? $invoices->pluck('client_tin')->filter()->last(),
                'phone'       => $first->hospital?->contact_phone ?? $first->client_contact,
                'email'       => $first->hospital?->contact_email ?? $first->client_email,
                'address'     => $first->hospital?->address,
            ],
            'period'          => ['from' => $from?->toDateString(), 'to' => $to?->toDateString()],
            'opening_balance' => $opening,
            'total_invoiced'  => (int) $inRange->sum('debit'),
            'total_paid'      => (int) $inRange->sum('credit'),
            'closing_balance' => $running,
            'balance_due'     => (int) $open->sum('balance'),
            'entries'         => $inRange,
            'open_invoices'   => $open->map(fn ($i) => self::invoiceRow($i, $today)),
        ]);
    }

    // POST /receivables/pay — one payment from a customer applied to their
    // oldest open invoices first (Clickhuduma's "Pay due"). Unlike
    // Clickhuduma there are no customer advances, so it can't exceed what's owed.
    public function pay(Request $request, InvoicePaymentService $payments)
    {
        abort_unless($request->user()->hasAccountantAuthority(), 403, 'You are not authorised to record payments.');
        $data = $request->validate([
            'amount'         => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'in:cash,bank_transfer,mobile_money,cheque'],
            'reference'      => ['nullable', 'string', 'max:255'],
            'paid_at'        => ['required', 'date'],
            'notes'          => ['nullable', 'string'],
        ]);

        $open = $this->openInvoices($this->customerInvoices($request))
            ->sortBy([['issue_date', 'asc'], ['id', 'asc']])->values();
        $owed = (int) $open->sum('balance');
        if ($data['amount'] > $owed) {
            throw ValidationException::withMessages(['amount' => 'This customer only owes TSh ' . number_format($owed) . '.']);
        }

        $allocations = DB::transaction(function () use ($open, $data, $payments, $request) {
            $left = $data['amount'];
            $done = [];
            foreach ($open as $inv) {
                if ($left <= 0) {
                    break;
                }
                $portion = min($left, $inv->balance);
                $payment = $payments->apply(Invoice::find($inv->id), [
                    'amount' => $portion,
                    'payment_method' => $data['payment_method'],
                    'reference' => $data['reference'] ?? null,
                    'paid_at' => $data['paid_at'],
                    'notes' => trim('Part of a lump payment of TSh ' . number_format($data['amount']) . '. ' . ($data['notes'] ?? '')),
                ], $request->user());
                $done[] = ['invoice_id' => $inv->id, 'invoice_number' => $inv->invoice_number, 'amount' => $portion,
                    'payment_number' => $payment->payment_number, 'balance_after' => $inv->balance - $portion];
                $left -= $portion;
            }
            return $done;
        });

        return response()->json(['data' => $allocations, 'remaining_balance' => $owed - $data['amount']], 201);
    }
}
