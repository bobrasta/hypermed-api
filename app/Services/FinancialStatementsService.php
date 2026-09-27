<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the annual financial statements (calendar year ending 31 December,
 * current + prior year columns) straight from the ledger, in the shape
 * FinancialStatementsPdfService renders.
 *
 * Every figure is rebuilt from dated `transactions` legs, not from
 * chart_of_accounts.balance (which has no period dimension):
 *  - balance-sheet lines = each account's signed balance as at a year end;
 *  - income-statement lines = revenue/expense activity within the year,
 *    excluding PeriodCloseService's CLOSE-* sweeps (which would otherwise
 *    zero the year's activity out);
 *  - retained earnings = Retained Earnings + other equity + all unclosed
 *    revenue − expense to date, same "current period earnings" idea as
 *    FinanceReportController::balanceSheet(), so the statement of financial
 *    position balances by construction;
 *  - the cash flow is derived from balance-sheet movements (indirect
 *    method), so its net change always equals the movement in cash.
 *
 * The narrative (directors, auditor, policies…) isn't ledger data — it lives
 * in the `financial_statements_profile` setting, editable from the export
 * dialog and seeded with the text of the 2024 audited statements.
 */
class FinancialStatementsService
{
    public const PROFILE_KEY = 'financial_statements_profile';

    // Account-code groups. Any asset/liability code not listed falls into
    // "other receivables" / "trade creditors" so nothing can drop off the
    // statement of financial position.
    private const CASH          = ['1001', '1002', '1004', '1008', '1009', '1010', '1011', '1012', '1013', '1014', '1015', '1018', '1019', '1020', '1021', '1022'];
    private const INVENTORY     = '1006';
    private const TRADE_RECV    = '1005';
    private const PPE           = ['1016', '1017'];
    private const VAT           = '2002';   // liability; a debit balance is VAT receivable
    private const INCOME_TAX    = '2014';   // liability; a debit balance is tax recoverable
    private const SHORT_TERM    = ['2003', '2029'];
    private const OVERDRAFT     = ['2009', '2015', '2016', '2017', '2018', '2019'];
    private const ACCRUED_INT   = ['2010', '2011', '2012', '2013', '2020', '2021', '2022', '2023'];
    private const SHARE_CAPITAL = ['3001', '3003', '3004'];
    private const CANCELLED     = '4004';
    private const COGS          = '5001';
    private const IMPORTS       = '5014';
    private const LOCAL_PURCH   = '5015';
    private const DIRECT        = ['5011', '5016', '5017', '5018', '5019', '5020', '5021', '5022', '5048', '5049', '5050'];
    private const FINANCE       = ['5012', '5051', '5052', '5053', '5054', '5055', '5056'];
    private const TAX_EXPENSE   = '5042';
    private const DIRECTOR_FEES = ['5023', '5057'];
    private const CSR           = '5039';

    /** @var Collection<int, ChartOfAccount> keyed by code */
    private Collection $accounts;

    public function build(int $year, bool $audited, string $signDate): array
    {
        $this->accounts = ChartOfAccount::with('category')->orderBy('code')->get()->keyBy('code');

        $ends = [$year => "{$year}-12-31", $year - 1 => ($year - 1) . '-12-31', $year - 2 => ($year - 2) . '-12-31'];
        $bal  = array_map(fn ($d) => $this->balancesAt($d), $ends);
        $act  = [$year => $this->activity($year), $year - 1 => $this->activity($year - 1)];

        $pos = [];
        foreach ([$year, $year - 1, $year - 2] as $y) {
            $pos[$y] = $this->position($bal[$y]);
        }
        $pl = [$year => $this->income($act[$year]), $year - 1 => $this->income($act[$year - 1])];

        $cy = $year;
        $py = $year - 1;
        $pair = fn (string $k, $src) => [$src[$cy][$k], $src[$py][$k]];

        // ---- Statement of financial position ----
        $sofp = [
            'ppe'               => $pair('ppe', $pos),
            'inventories'       => $pair('inventories', $pos),
            'taxation'          => $pair('tax_asset', $pos),
            'trade_receivables' => $pair('trade_receivables', $pos),
            'vat_receivables'   => $pair('vat_receivable', $pos),
            'other_receivables' => $pair('other_receivables', $pos),
            'cash'              => $pair('cash', $pos),
            'current_assets'    => $pair('current_assets', $pos),
            'total_assets'      => $pair('total_assets', $pos),
            'share_capital'     => $pair('share_capital', $pos),
            'retained'          => $pair('retained', $pos),
            'total_equity'      => $pair('total_equity', $pos),
            'creditors'         => $pair('creditors', $pos),
            'tax_payable'       => $pair('tax_payable', $pos),
            'short_term'        => $pair('short_term', $pos),
            'overdraft'         => $pair('overdraft', $pos),
            'total_liabilities' => $pair('total_liabilities', $pos),
            'total_eq_liab'     => $pair('total_eq_liab', $pos),
            'balanced'          => $pos[$cy]['balanced'] && $pos[$py]['balanced'],
        ];

        // ---- Statement of comprehensive income ----
        $soci = [];
        foreach (['revenue', 'cost_of_sales', 'gross_profit', 'opex', 'depreciation', 'finance', 'total_exp', 'pbt', 'tax', 'pat'] as $k) {
            $soci[$k] = $pair($k, $pl);
        }

        // ---- Cash flow + equity, per year ----
        $cfYear = fn (int $y) => $this->cashFlow($pos[$y - 1], $pos[$y], $pl[$y], $bal[$y - 1], $bal[$y]);
        $cf = [$cy => $cfYear($cy), $py => $cfYear($py)];
        $cashflow = [];
        foreach (['pbt', 'depreciation', 'op_before_wc', 'cash_ops', 'tax_paid', 'after_tax', 'net_operating', 'financing_total', 'net_change', 'opening', 'closing'] as $k) {
            $cashflow[$k] = [$cf[$cy][$k], $cf[$py][$k]];
        }
        foreach (['wc', 'other_operating', 'investing', 'financing'] as $k) {
            $cashflow[$k] = array_map(fn ($i) => [$cf[$cy][$k][$i][0], $cf[$cy][$k][$i][1], $cf[$py][$k][$i][1]], array_keys($cf[$cy][$k]));
        }

        $equity = [];
        foreach ([$py, $cy] as $y) {
            $open  = [$pos[$y - 1]['share_capital'], $pos[$y - 1]['retained']];
            $close = [$pos[$y]['share_capital'], $pos[$y]['retained']];
            $equity[] = [
                'year'    => $y,
                'open'    => $open,
                'capital' => $close[0] - $open[0],
                'profit'  => $pl[$y]['pat'],
                'assess'  => $close[1] - $open[1] - $pl[$y]['pat'],
                'close'   => $close,
            ];
        }

        // ---- Notes ----
        $lines = fn (array $codes, array $src) => $this->lines($codes, $src[$cy], $src[$py]);
        $revenueCodes = $this->codesOfType('revenue', [self::CANCELLED]);
        $cosCodes     = array_merge([self::COGS, self::IMPORTS, self::LOCAL_PURCH], self::DIRECT);
        $opexCodes    = $this->codesOfType('expense', array_merge($cosCodes, self::FINANCE, [self::TAX_EXPENSE]));

        $turnover = $lines($revenueCodes, $act);
        // 4004 is a revenue account with a debit (negative) balance — already signed.
        $cancelled = [$act[$cy][self::CANCELLED] ?? 0, $act[$py][self::CANCELLED] ?? 0];
        if ($cancelled[0] || $cancelled[1]) {
            $turnover[] = ['Cancelled sales', $cancelled[0], $cancelled[1]];
        }

        $cosNote = [];
        foreach ([$cy, $py] as $i => $y) {
            $a = $act[$y];
            $opening   = $pos[$y - 1]['inventories'];
            $closing   = $pos[$y]['inventories'];
            $imports   = $a[self::IMPORTS] ?? 0;
            $local     = $a[self::LOCAL_PURCH] ?? 0;
            $stockIn   = $this->debitsIn(self::INVENTORY, $y);
            $available = $opening + $imports + $local + $stockIn;
            $direct_total = $this->sum($a, self::DIRECT);
            $total     = $available + $direct_total;
            $costSold  = $pl[$y]['cost_of_sales'];
            $cosNote[$i] = compact('opening', 'imports', 'local', 'stockIn', 'available', 'direct_total', 'total', 'closing', 'costSold')
                + ['adjust' => $costSold - ($total - $closing), 'gross' => $pl[$y]['gross_profit']];
        }
        $cos = ['direct' => $lines(self::DIRECT, $act)];
        foreach (array_keys($cosNote[0]) as $k) {
            $cos[$k] = [$cosNote[0][$k], $cosNote[1][$k]];
        }

        $tax = [];
        foreach ([$cy, $py] as $i => $y) {
            $taxBf = $bal[$y - 1][self::INCOME_TAX] ?? 0;
            $taxCf = $bal[$y][self::INCOME_TAX] ?? 0;
            $row = [
                'pbt'       => $pl[$y]['pbt'],
                'dep'       => $pl[$y]['depreciation'],
                'taxable'   => $pl[$y]['pbt'] + $pl[$y]['depreciation'],
                'tax_year'  => $pl[$y]['tax'],
                'bal_bf'    => $taxBf,
                'charged'   => $this->creditsIn(self::INCOME_TAX, $y),
                'paid'      => -$this->debitsIn(self::INCOME_TAX, $y),
                'bal_cf'    => $taxCf,
            ];
            foreach ($row as $k => $v) {
                $tax[$k][$i] = $v;
            }
        }

        $retained = [
            ['Brought forward balances', $pos[$py]['retained'], $pos[$py - 1]['retained']],
            ['Profit after taxation', $pl[$cy]['pat'], $pl[$py]['pat']],
        ];
        $adj = [$equity[1]['assess'], $equity[0]['assess']];
        if ($adj[0] || $adj[1]) {
            $retained[] = ['Tax assessments & other equity adjustments', $adj[0], $adj[1], 'i'];
        }

        // Receivables / payables notes, splitting the two net-position
        // accounts (VAT, income tax) by which side of the sheet they sit on.
        $otherRecv = $this->otherReceivableCodes();
        $receivables = $lines([self::TRADE_RECV], $bal);
        if ($sofp['vat_receivables'][0] || $sofp['vat_receivables'][1]) {
            $receivables[] = ['VAT receivable', $sofp['vat_receivables'][0], $sofp['vat_receivables'][1]];
        }
        $receivables = array_merge($receivables, $lines($otherRecv, $bal));

        $creditorCodes = $this->creditorCodes();
        $payables = $lines(array_diff($creditorCodes, self::ACCRUED_INT), $bal);
        $vatPayable = [max(0, $bal[$cy][self::VAT] ?? 0), max(0, $bal[$py][self::VAT] ?? 0)];
        if ($vatPayable[0] || $vatPayable[1]) {
            $payables[] = ['VAT payable', $vatPayable[0], $vatPayable[1]];
        }
        $accrued = $lines(self::ACCRUED_INT, $bal);

        // PPE schedule — one column per PPE account. There's no depreciation
        // account in the chart, so depreciation rows are zero until one exists.
        $ppeCodes = array_values(array_filter(self::PPE, fn ($c) => $this->accounts->has($c)));
        $assets = [
            'classes'    => array_map(fn ($c) => [$this->accounts[$c]->name, ''], $ppeCodes),
            'cost_open'  => array_map(fn ($c) => $bal[$py][$c] ?? 0, $ppeCodes),
            'additions'  => array_map(fn ($c) => $this->debitsIn($c, $cy), $ppeCodes),
            'disposal'   => array_map(fn ($c) => $this->creditsIn($c, $cy), $ppeCodes),
            'dep_open'   => array_fill(0, count($ppeCodes), 0),
            'dep_charge' => array_fill(0, count($ppeCodes), 0),
            'nbv_prior'  => array_map(fn ($c) => $bal[$py][$c] ?? 0, $ppeCodes),
        ];

        $profile = self::profile();
        $directorFees = $this->sum($act[$cy], self::DIRECTOR_FEES);

        return array_merge($profile, [
            'year'            => $cy,
            'prior_year'      => $py,
            'year_end'        => "31ST DECEMBER {$cy}",
            'year_end_text'   => "31st December, {$cy}",
            'period_text'     => "31 December {$cy}",
            'currency'        => 'Tshs',
            'audited'         => $audited,
            'issue_month'     => strtoupper(date('F Y', strtotime($signDate))),
            'sign_date'       => date('d/m/Y', strtotime($signDate)),
            'report_date'     => date('jS F, Y', strtotime($signDate)),
            'employees'       => $profile['employees'] ?: User::where('is_active', true)->count(),
            'director_fees_text' => self::millions($directorFees),
            'csr_amount_text'    => self::millions($act[$cy][self::CSR] ?? 0),
            'results_summary' => [
                ['Profit from operations', $pl[$cy]['pbt'], $pl[$py]['pbt']],
                ['Taxation', -$pl[$cy]['tax'], -$pl[$py]['tax']],
                ['Profit after tax for the year', $pl[$cy]['pat'], $pl[$py]['pat']],
            ],
            'sofp'     => $sofp,
            'soci'     => $soci,
            'cashflow' => $cashflow,
            'equity'   => $equity,
            'turnover' => $turnover,
            'turnover_total' => $soci['revenue'],
            'cos'      => $cos,
            'opex'     => $lines($opexCodes, $act),
            'opex_total' => $soci['opex'],
            'finance'  => $lines(self::FINANCE, $act),
            'finance_total' => $soci['finance'],
            'share_capital_note' => $lines(self::SHARE_CAPITAL, $bal),
            'share_capital' => $sofp['share_capital'],
            'tax'      => $tax,
            'retained' => $retained,
            'retained_total' => $sofp['retained'],
            'cash_note' => $lines(self::CASH, $bal),
            'cash_total' => $sofp['cash'],
            'receivables' => $receivables,
            'receivables_total' => [
                $sofp['trade_receivables'][0] + $sofp['vat_receivables'][0] + $sofp['other_receivables'][0],
                $sofp['trade_receivables'][1] + $sofp['vat_receivables'][1] + $sofp['other_receivables'][1],
            ],
            'payables' => $payables,
            'payables_total' => [$sofp['creditors'][0] - $this->sum($bal[$cy], self::ACCRUED_INT), $sofp['creditors'][1] - $this->sum($bal[$py], self::ACCRUED_INT)],
            'accrued_interest' => $accrued,
            'accrued_interest_total' => [$this->sum($bal[$cy], self::ACCRUED_INT), $this->sum($bal[$py], self::ACCRUED_INT)],
            'overdrafts' => $lines(self::OVERDRAFT, $bal),
            'overdrafts_total' => $sofp['overdraft'],
            'short_term_note' => $lines(self::SHORT_TERM, $bal),
            'short_term_total' => $sofp['short_term'],
            'assets' => $assets,
        ]);
    }

    // ---------------------------------------------------------------- ledger

    /** Signed (normal-balance-positive) balance of every account at end of $date. */
    private function balancesAt(string $date): array
    {
        return $this->signedSums(fn ($q) => $q->where('t.created_at', '<=', "{$date} 23:59:59"));
    }

    /** Revenue/expense activity within a calendar year, closing entries excluded. */
    private function activity(int $year): array
    {
        return $this->signedSums(fn ($q) => $q
            ->whereBetween('t.created_at', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"])
            ->where(fn ($w) => $w->whereNull('t.reference')->orWhere('t.reference', 'not like', 'CLOSE-%')));
    }

    private function signedSums(callable $scope): array
    {
        $q = DB::table('transactions as t')
            ->join('chart_of_accounts as a', 'a.id', '=', 't.account_id')
            ->join('account_categories as c', 'c.id', '=', 'a.category_id')
            ->groupBy('a.code')
            ->selectRaw("a.code, SUM(CASE WHEN (t.type = 'debit') = (c.type IN ('asset', 'expense')) THEN t.amount ELSE -t.amount END) AS total");
        $scope($q);

        return $q->pluck('total', 'code')->map(fn ($v) => (int) $v)->all();
    }

    private function debitsIn(string $code, int $year): int
    {
        return $this->legsIn($code, $year, 'debit');
    }

    private function creditsIn(string $code, int $year): int
    {
        return $this->legsIn($code, $year, 'credit');
    }

    private function legsIn(string $code, int $year, string $type): int
    {
        $id = $this->accounts->get($code)?->id;
        if (! $id) {
            return 0;
        }

        return (int) DB::table('transactions')->where('account_id', $id)->where('type', $type)
            ->whereBetween('created_at', ["{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59"])
            ->where(fn ($w) => $w->whereNull('reference')->orWhere('reference', 'not like', 'CLOSE-%'))
            ->sum('amount');
    }

    // ------------------------------------------------------------- statements

    private function position(array $b): array
    {
        $vat = $b[self::VAT] ?? 0;
        $incomeTax = $b[self::INCOME_TAX] ?? 0;

        $p = [
            'ppe'               => $this->sum($b, self::PPE),
            'inventories'       => $b[self::INVENTORY] ?? 0,
            'tax_asset'         => max(0, -$incomeTax),
            'trade_receivables' => $b[self::TRADE_RECV] ?? 0,
            'vat_receivable'    => max(0, -$vat),
            'other_receivables' => $this->sum($b, $this->otherReceivableCodes()),
            'cash'              => $this->sum($b, self::CASH),
            'share_capital'     => $this->sum($b, self::SHARE_CAPITAL),
            'retained'          => $this->sum($b, $this->codesOfType('equity', self::SHARE_CAPITAL))
                                 + $this->sum($b, $this->codesOfType('revenue'))
                                 - $this->sum($b, $this->codesOfType('expense')),
            'creditors'         => $this->sum($b, $this->creditorCodes()) + max(0, $vat),
            'tax_payable'       => max(0, $incomeTax),
            'short_term'        => $this->sum($b, self::SHORT_TERM),
            'overdraft'         => $this->sum($b, self::OVERDRAFT),
        ];
        $p['current_assets']    = $p['inventories'] + $p['tax_asset'] + $p['trade_receivables'] + $p['vat_receivable'] + $p['other_receivables'] + $p['cash'];
        $p['total_assets']      = $p['ppe'] + $p['current_assets'];
        $p['total_equity']      = $p['share_capital'] + $p['retained'];
        $p['total_liabilities'] = $p['creditors'] + $p['tax_payable'] + $p['short_term'] + $p['overdraft'];
        $p['total_eq_liab']     = $p['total_equity'] + $p['total_liabilities'];
        $p['balanced']          = $p['total_assets'] === $p['total_eq_liab'];

        return $p;
    }

    private function income(array $a): array
    {
        $revenue = $this->sum($a, $this->codesOfType('revenue'));
        $cos     = $this->sum($a, array_merge([self::COGS, self::IMPORTS, self::LOCAL_PURCH], self::DIRECT));
        $finance = $this->sum($a, self::FINANCE);
        $tax     = $a[self::TAX_EXPENSE] ?? 0;
        $opex    = $this->sum($a, $this->codesOfType('expense')) - $cos - $finance - $tax;
        $dep     = 0; // no depreciation account in the chart yet

        $gross = $revenue - $cos;
        $totalExp = $opex + $dep + $finance;

        return [
            'revenue' => $revenue, 'cost_of_sales' => $cos, 'gross_profit' => $gross,
            'opex' => $opex, 'depreciation' => $dep, 'finance' => $finance, 'total_exp' => $totalExp,
            'pbt' => $gross - $totalExp, 'tax' => $tax, 'pat' => $gross - $totalExp - $tax,
        ];
    }

    /**
     * Indirect-method cash flow for one year from its opening/closing
     * positions. Every non-cash balance-sheet movement lands in exactly one
     * line, so net_change == closing cash − opening cash.
     */
    private function cashFlow(array $open, array $close, array $pl, array $bOpen, array $bClose): array
    {
        $d = fn (string $k) => $close[$k] - $open[$k];
        $vatNet = fn (array $b) => $b[self::VAT] ?? 0;
        $taxNet = fn (array $b) => $b[self::INCOME_TAX] ?? 0;

        $wc = [
            ['(Increase) / Decrease in Inventories', -$d('inventories')],
            ['(Increase) / Decrease in Receivables', -($d('trade_receivables') + $d('other_receivables'))],
            ['Increase / (Decrease) in VAT payable', $vatNet($bClose) - $vatNet($bOpen)],
            ['Increase / (Decrease) in Creditors & Accruals', ($close['creditors'] - max(0, $vatNet($bClose))) - ($open['creditors'] - max(0, $vatNet($bOpen)))],
            ['Increase / (Decrease) in short-term financings', $d('short_term')],
        ];
        $opBeforeWc = $pl['pbt'] + $pl['depreciation'];
        $cashOps = $opBeforeWc + array_sum(array_column($wc, 1));
        $taxPaid = -$pl['tax'] + ($taxNet($bClose) - $taxNet($bOpen));
        $afterTax = $cashOps + $taxPaid;

        // Equity movements that didn't come through profit (e.g. prior-year
        // tax assessments charged straight to equity) — cash paid to TRA.
        $equityAdj = $d('retained') - $pl['pat'];
        $other = [['Tax assessments & other equity adjustments', $equityAdj]];
        $netOperating = $afterTax + $equityAdj;

        $investing = [['Purchases of non-current assets', -$d('ppe')]];
        $financing = [['Bank overdraft', $d('overdraft')], ['Share capital introduced', $d('share_capital')]];
        $financingTotal = array_sum(array_column($financing, 1));
        $netChange = $netOperating + $investing[0][1] + $financingTotal;

        return [
            'pbt' => $pl['pbt'], 'depreciation' => $pl['depreciation'], 'op_before_wc' => $opBeforeWc,
            'wc' => $wc, 'cash_ops' => $cashOps, 'tax_paid' => $taxPaid, 'after_tax' => $afterTax,
            'other_operating' => $other, 'net_operating' => $netOperating,
            'investing' => $investing, 'financing' => $financing, 'financing_total' => $financingTotal,
            'net_change' => $netChange, 'opening' => $open['cash'], 'closing' => $close['cash'],
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function codesOfType(string $type, array $except = []): array
    {
        return $this->accounts->filter(fn ($a) => $a->category->type === $type && ! in_array($a->code, $except, true))
            ->keys()->map(fn ($c) => (string) $c)->all();
    }

    private function otherReceivableCodes(): array
    {
        return $this->codesOfType('asset', array_merge(self::CASH, self::PPE, [self::INVENTORY, self::TRADE_RECV]));
    }

    private function creditorCodes(): array
    {
        return $this->codesOfType('liability', array_merge(self::SHORT_TERM, self::OVERDRAFT, [self::VAT, self::INCOME_TAX]));
    }

    private function sum(array $b, array $codes): int
    {
        return array_sum(array_map(fn ($c) => $b[$c] ?? 0, $codes));
    }

    /** One note line per account that's non-zero in either year: [name, cy, py]. */
    private function lines(array $codes, array $cy, array $py): array
    {
        $out = [];
        foreach ($codes as $c) {
            $a = $cy[$c] ?? 0;
            $b = $py[$c] ?? 0;
            if (($a || $b) && $this->accounts->has($c)) {
                $out[] = [$this->accounts[$c]->name, $a, $b];
            }
        }

        return $out;
    }

    private static function millions(int $amount): string
    {
        return rtrim(rtrim(number_format($amount / 1_000_000, 1), '0'), '.') . ' million';
    }

    // ---------------------------------------------------------------- profile

    public static function profile(): array
    {
        $saved = json_decode((string) Setting::get(self::PROFILE_KEY, ''), true);

        return is_array($saved) ? array_replace(self::defaultProfile(), $saved) : self::defaultProfile();
    }

    /** The narrative of the 2024 audited statements (YOI 2024, Fincare & Co). */
    public static function defaultProfile(): array
    {
        return [
            'company'        => 'HYPERMED HEALTHCARE LIMITED',
            'company_short'  => 'Hypermed Healthcare Ltd',
            'cover_address'  => ['P.O. Box 14118', 'Dar es Salaam, Tanzania'],
            'principal_activities' => 'During the year of income ended the company has engaged medical equipments and laboratory reagents supplies.',
            'directors' => [
                ['Moses Deogratius Kiduduye', 'Biomedical Engineer', 'Tanzanian'],
                ['Maimuna Said Teka', 'Business woman', 'Tanzanian'],
            ],
            'md_note_name' => 'Mr. Moses Deogratius Kiduduye',
            'md_note'      => 'has continued to actively involve in the management and daily operations during the year ended. The board cited his commitment and professional competence as key to his continuation.',
            'shareholders' => [
                ['Moses Deogratius Kiduduye', 'Tanzanian', 101000],
                ['Maimuna Said Teka', 'Tanzanian', 73000],
            ],
            'employees' => null,
            'chairman'  => 'Moses Deogratias Kiduduye',
            'finance_head' => ['name' => 'ATUPELE M. MWAIKA', 'title' => 'Finance Manager', 'reg_no' => '4609'],
            'auditor' => [
                'name'          => 'FINCARE & CO LTD',
                'firm'          => 'FINCARE AND COMPANY',
                'short'         => 'Fincare & Co',
                'cpa_line'      => 'Fincare & Co Ltd, CPA',
                'cover_address' => 'P.O. Box 222528, Dar es Salaam',
                'title'         => 'CERTIFIED PUBLIC ACCOUNTANTS',
                'city'          => 'DAR ES SALAAM',
                'tagline'       => 'Certified Public Accountants, Tax and Management accountants',
                'letterhead'    => ['Ubungo Kisiwani Market close', 'P.O. Box 22528, Dar es Salaam', 'Tanzania', 'Phone: +255 (0) 683 686869, (0) 692 507660'],
                'email'         => 'fincarecompany2007@gmail.com',
                'partner'       => 'G. N KASARO - MSc, CIMA, FTAA, FCPA',
                'po_box_text'   => 'P.O BOX 22825, Dar es Salaam',
            ],
            'policies' => [
                ['Basis of preparation', 'The financial statements are prepared in accordance with and comply with International Financial Reporting Standards. The financial statements are prepared under the historical cost convention.'],
                ['Revenue recognition', "Revenue is recognised on an accruals' basis and when goods and services are supplied and accepted by the customer. The revenue represents sales and commission obtained from sales of medical supplies and reagents."],
                ['Translation of foreign currencies', 'Transactions in foreign currency during the year are converted into Tanzanian Shillings at exchange rates ruling at the date of the transactions. Foreign currency monetary assets and liabilities at the balance sheet date are translated into Tanzanian Shillings at the exchange rates prevailing at that date. Resulting exchange differences are recognized in the profit and loss account for the year.'],
                ['Trade and other debtors', 'Trade and other receivables are stated at nominal value less write down for any amounts expected to be irrecoverable.'],
                ['Trade and other payables', 'Trade and other payables are stated at their costs.'],
                ['Taxation', "Tax on the profit or loss for the year comprises current and deferred tax.\nCurrent tax is provided on the results in the year as shown in the accounts adjusted in accordance with tax legislation."],
                ['Depreciation', 'Assets are depreciated at a reducing balance method using the following rates'],
                ['Closing Stock', 'This includes the amount of inventory held as at the reporting date.'],
            ],
            'dep_rates' => [['Furniture & Fittings', '12.50%'], ['Computers', '37.50%'], ['Equipments', '12.50%']],
            'dep_note'  => '',
        ];
    }
}
