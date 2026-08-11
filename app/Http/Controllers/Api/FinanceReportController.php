<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\StockMovement;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceReportController extends Controller
{
    private const NORMAL_BALANCE = [
        'asset'     => 'debit',
        'expense'   => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'revenue'   => 'credit',
    ];

    // Every account's signed balance, split into Dr/Cr columns by its category's
    // normal balance. Debits should always equal credits by construction — every
    // AccountingService posting is balance-checked before it's written — so
    // `balanced: false` here would mean a real bug in the ledger, not a business
    // condition. All-time snapshot only (chart_of_accounts.balance has no
    // historical/period dimension); for period P&L see Finance E instead.
    public function trialBalance()
    {
        $accounts = ChartOfAccount::with('category')->where('status', 'active')->orderBy('code')->get();

        $rows = $accounts->map(function ($account) {
            $normal = self::NORMAL_BALANCE[$account->category->type];
            $balance = $account->balance;

            return [
                'code'    => $account->code,
                'name'    => $account->name,
                'type'    => $account->category->type,
                'debit'   => $normal === 'debit'  ? max($balance, 0) : max(-$balance, 0),
                'credit'  => $normal === 'credit' ? max($balance, 0) : max(-$balance, 0),
            ];
        });

        $totalDebit  = (int) $rows->sum('debit');
        $totalCredit = (int) $rows->sum('credit');

        return response()->json(['data' => [
            'accounts'      => $rows,
            'total_debit'   => $totalDebit,
            'total_credit'  => $totalCredit,
            'balanced'      => $totalDebit === $totalCredit,
        ]]);
    }

    // Input/output VAT netting for a period — a legitimate VAT-payable calculation
    // (output VAT collected on sales minus input VAT paid on expenses/vendor bills),
    // independent of the ledger's all-time VAT Payable balance so it can be scoped
    // to any reporting period (e.g. a monthly TRA filing).
    public function vat(Request $request)
    {
        $from = $request->date_from ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->date_to   ?? Carbon::now()->endOfMonth()->toDateString();

        $outputInvoices = Invoice::whereBetween('issue_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->where('tax_amount', '>', 0)
            ->orderBy('issue_date')
            ->get(['id', 'invoice_number', 'issue_date', 'subtotal', 'tax_amount', 'total']);

        $inputExpenses = Expense::whereBetween('expense_date', [$from, $to])
            ->where('tax_amount', '>', 0)
            ->orderBy('expense_date')
            ->get(['id', 'name', 'expense_date', 'amount', 'tax_amount']);

        $inputBills = VendorBill::whereBetween('issue_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->where('tax_amount', '>', 0)
            ->orderBy('issue_date')
            ->get(['id', 'bill_number', 'issue_date', 'subtotal', 'tax_amount', 'total']);

        $outputVat = (int) $outputInvoices->sum('tax_amount');
        $inputVat  = (int) $inputExpenses->sum('tax_amount') + (int) $inputBills->sum('tax_amount');

        return response()->json(['data' => [
            'period_from'  => $from,
            'period_to'    => $to,
            'output_vat'   => $outputVat,
            'input_vat'    => $inputVat,
            'net_vat_payable' => $outputVat - $inputVat,
            'output_invoices' => $outputInvoices->map(fn ($i) => [
                'id' => $i->id, 'invoice_number' => $i->invoice_number, 'issue_date' => $i->issue_date?->toDateString(),
                'subtotal' => $i->subtotal, 'tax_amount' => $i->tax_amount, 'total' => $i->total,
            ]),
            'input_expenses' => $inputExpenses->map(fn ($e) => [
                'id' => $e->id, 'name' => $e->name, 'expense_date' => $e->expense_date?->toDateString(),
                'amount' => $e->amount, 'tax_amount' => $e->tax_amount,
            ]),
            'input_bills' => $inputBills->map(fn ($b) => [
                'id' => $b->id, 'bill_number' => $b->bill_number, 'issue_date' => $b->issue_date?->toDateString(),
                'subtotal' => $b->subtotal, 'tax_amount' => $b->tax_amount, 'total' => $b->total,
            ]),
        ]]);
    }

    // Revenue - COGS - Expenses, computed straight from source tables and filtered
    // by date range — deliberately independent of chart_of_accounts.balance, which
    // is an all-time cumulative figure with no period scoping. Revenue is recognized
    // on invoice issue_date (accrual basis, net of VAT). COGS is approximated from
    // stock-issue movements in the period valued at InventoryItem's *current*
    // weighted-average unit_cost — StockMovement doesn't capture historical cost at
    // time of sale, so this is a known simplification, not a point-in-time actual.
    public function profitLoss(Request $request)
    {
        $from = $request->date_from ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->date_to   ?? Carbon::now()->endOfMonth()->toDateString();

        $revenue = (int) Invoice::whereBetween('issue_date', [$from, $to])
            ->where('status', '!=', 'cancelled')
            ->sum('subtotal');

        $cogs = (int) StockMovement::query()
            ->join('inventory_items', 'inventory_items.id', '=', 'stock_movements.inventory_item_id')
            ->where('stock_movements.type', 'issue')
            ->whereBetween('stock_movements.created_at', ["{$from} 00:00:00", "{$to} 23:59:59"])
            ->sum(DB::raw('ABS(stock_movements.quantity) * inventory_items.unit_cost'));

        $expensesByCategory = Expense::query()
            ->join('expense_categories', 'expense_categories.id', '=', 'expenses.category_id')
            ->whereBetween('expenses.expense_date', [$from, $to])
            ->groupBy('expense_categories.id', 'expense_categories.name')
            ->orderByDesc(DB::raw('SUM(expenses.amount)'))
            ->get(['expense_categories.name as category_name', DB::raw('SUM(expenses.amount) as total')]);

        $totalExpenses = (int) $expensesByCategory->sum('total');
        $grossProfit   = $revenue - $cogs;
        $netProfit     = $grossProfit - $totalExpenses;

        return response()->json(['data' => [
            'period_from'    => $from,
            'period_to'      => $to,
            'revenue'        => $revenue,
            'cogs'           => $cogs,
            'gross_profit'   => $grossProfit,
            'total_expenses' => $totalExpenses,
            'net_profit'     => $netProfit,
            'expenses_by_category' => $expensesByCategory->map(fn ($e) => [
                'category' => $e->category_name, 'total' => (int) $e->total,
            ]),
        ]]);
    }

    // Assets = Liabilities + Equity, where Equity includes both formally-closed
    // balances (Owner's Capital, Retained Earnings from a period close) *and*
    // unclosed Revenue-minus-Expense as "current period earnings" — so this
    // always balances by construction, without needing PeriodCloseService to
    // have run first (unlike gwbook-api's reference, whose equity was a plug
    // specifically because it didn't do this).
    public function balanceSheet()
    {
        $accounts = ChartOfAccount::with('category')->where('status', 'active')->orderBy('code')->get();
        $byType = $accounts->groupBy(fn ($a) => $a->category->type);

        $assets      = $byType->get('asset', collect());
        $liabilities = $byType->get('liability', collect());
        $equity      = $byType->get('equity', collect());
        $revenue     = $byType->get('revenue', collect());
        $expense     = $byType->get('expense', collect());

        $totalAssets      = (int) $assets->sum('balance');
        $totalLiabilities = (int) $liabilities->sum('balance');
        $formalEquity     = (int) $equity->sum('balance');
        $currentPeriodEarnings = (int) $revenue->sum('balance') - (int) $expense->sum('balance');
        $totalEquity      = $formalEquity + $currentPeriodEarnings;

        $mapAccounts = fn ($accts) => $accts->map(fn ($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->balance])->values();

        return response()->json(['data' => [
            'assets'            => $mapAccounts($assets),
            'total_assets'      => $totalAssets,
            'liabilities'       => $mapAccounts($liabilities),
            'total_liabilities' => $totalLiabilities,
            'equity'            => $mapAccounts($equity),
            'current_period_earnings' => $currentPeriodEarnings,
            'total_equity'      => $totalEquity,
            'balanced'          => $totalAssets === $totalLiabilities + $totalEquity,
        ]]);
    }

    // Unpaid/partially-paid invoices bucketed by days overdue — the detail
    // behind the single lumped "outstanding" figure the Sales revenue screen
    // already shows.
    public function arAging()
    {
        $today = Carbon::today();

        $invoices = Invoice::with('hospital:id,name')
            ->whereIn('status', ['pending', 'partial', 'overdue', 'sent'])
            ->get(['id', 'invoice_number', 'hospital_id', 'client_name', 'due_date', 'total', 'amount_paid']);

        $buckets = ['current' => 0, 'days_1_30' => 0, 'days_31_60' => 0, 'days_61_90' => 0, 'days_90_plus' => 0];
        $items = [];

        foreach ($invoices as $inv) {
            $balance = $inv->total - $inv->amount_paid;
            if ($balance <= 0) {
                continue;
            }

            $daysOverdue = Carbon::parse($inv->due_date)->diffInDays($today, false);
            $bucket = match (true) {
                $daysOverdue <= 0  => 'current',
                $daysOverdue <= 30 => 'days_1_30',
                $daysOverdue <= 60 => 'days_31_60',
                $daysOverdue <= 90 => 'days_61_90',
                default            => 'days_90_plus',
            };

            $buckets[$bucket] += $balance;
            $items[] = [
                'invoice_id' => $inv->id, 'invoice_number' => $inv->invoice_number,
                'client_name' => $inv->hospital?->name ?? $inv->client_name,
                'due_date' => $inv->due_date?->toDateString(),
                'balance_due' => $balance, 'days_overdue' => max($daysOverdue, 0), 'bucket' => $bucket,
            ];
        }

        return response()->json(['data' => [
            'buckets'      => $buckets,
            'total_outstanding' => array_sum($buckets),
            'invoices'     => $items,
        ]]);
    }

    // Simple cash-in vs cash-out for a period across all payment methods —
    // complements Bank Reconciliation (Finance G), which is bank-only and
    // transaction-level; this is a quick all-methods total.
    public function cashFlow(Request $request)
    {
        $from = $request->date_from ?? Carbon::now()->startOfMonth()->toDateString();
        $to   = $request->date_to   ?? Carbon::now()->endOfMonth()->toDateString();

        $cashInByMethod = Payment::whereBetween('paid_at', [$from, $to])
            ->groupBy('payment_method')
            ->get(['payment_method', DB::raw('SUM(amount) as total')]);

        $expenseCashOut = (int) Expense::whereBetween('expense_date', [$from, $to])
            ->selectRaw('COALESCE(SUM(amount + tax_amount), 0) as total')->value('total');

        $billPaymentCashOut = (int) VendorBillPayment::whereBetween('paid_at', [$from, $to])->sum('amount');

        $cashIn  = (int) $cashInByMethod->sum('total');
        $cashOut = $expenseCashOut + $billPaymentCashOut;

        return response()->json(['data' => [
            'period_from'   => $from,
            'period_to'     => $to,
            'cash_in'       => $cashIn,
            'cash_in_by_method' => $cashInByMethod->map(fn ($p) => ['method' => $p->payment_method, 'total' => (int) $p->total]),
            'cash_out'      => $cashOut,
            'cash_out_expenses'      => $expenseCashOut,
            'cash_out_vendor_bills'  => $billPaymentCashOut,
            'net_cash_flow' => $cashIn - $cashOut,
        ]]);
    }
}
