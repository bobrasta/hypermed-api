<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use InvalidArgumentException;

/**
 * Formal period-end closing: zeroes every Revenue and Expense account and
 * sweeps the net result into Retained Earnings, via one balanced posting
 * through AccountingService — same as a manual "close the books" entry in
 * textbook double-entry bookkeeping.
 *
 * Not required for the Balance Sheet to be correct day-to-day (see
 * FinanceReportController::balanceSheet(), which includes unclosed
 * Revenue-minus-Expense as "current period earnings" so the sheet always
 * balances) — this is for formally rolling a period's result into
 * Retained Earnings, e.g. at year-end.
 */
class PeriodCloseService
{
    private const RETAINED_EARNINGS_CODE = '3002';

    public function __construct(private AccountingService $accounting) {}

    /**
     * @return array{net_income: int, accounts_closed: int}
     */
    public function closeToRetainedEarnings(): array
    {
        $revenueAccounts = ChartOfAccount::whereHas('category', fn ($q) => $q->where('type', 'revenue'))
            ->where('status', 'active')->where('balance', '!=', 0)->get();
        $expenseAccounts = ChartOfAccount::whereHas('category', fn ($q) => $q->where('type', 'expense'))
            ->where('status', 'active')->where('balance', '!=', 0)->get();

        if ($revenueAccounts->isEmpty() && $expenseAccounts->isEmpty()) {
            return ['net_income' => 0, 'accounts_closed' => 0];
        }

        $totalRevenue = (int) $revenueAccounts->sum('balance');
        $totalExpense = (int) $expenseAccounts->sum('balance');
        $netIncome = $totalRevenue - $totalExpense;

        $entries = [];
        foreach ($revenueAccounts as $account) {
            $entries[] = ['account_id' => $account->id, 'type' => 'debit', 'amount' => $account->balance];
        }
        foreach ($expenseAccounts as $account) {
            $entries[] = ['account_id' => $account->id, 'type' => 'credit', 'amount' => $account->balance];
        }

        $retainedEarningsId = $this->accounting->accountIdByCode(self::RETAINED_EARNINGS_CODE);
        if ($netIncome > 0) {
            $entries[] = ['account_id' => $retainedEarningsId, 'type' => 'credit', 'amount' => $netIncome];
        } elseif ($netIncome < 0) {
            $entries[] = ['account_id' => $retainedEarningsId, 'type' => 'debit', 'amount' => abs($netIncome)];
        }

        if (count($entries) < 2) {
            throw new InvalidArgumentException('Nothing to close.');
        }

        $this->accounting->recordTransaction(
            $entries,
            'Period close — net income swept to Retained Earnings',
            'CLOSE-' . now()->format('YmdHis'),
        );

        return ['net_income' => $netIncome, 'accounts_closed' => $revenueAccounts->count() + $expenseAccounts->count()];
    }
}
