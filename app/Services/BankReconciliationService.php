<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\BankStatementLine;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\VendorBillPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bank reconciliation: import a bank statement CSV, auto-match its lines
 * against system-side bank money movements (Payments received, Expenses/
 * VendorBillPayments paid out), and confirm the reconciled balance agrees
 * with the bank's own statement balance before allowing "complete".
 *
 * Deliberately reads live from payments/expenses/vendor_bill_payments, not
 * from the ledger — same "period reports read from source tables" philosophy
 * used throughout this module (see FinanceReportController).
 */
class BankReconciliationService
{
    private const DATE_FORMATS = ['d.m.Y', 'Y-m-d', 'd/m/Y'];
    private const MATCH_WINDOW_DAYS = 2;

    public function createOrGet(string $periodFrom, string $periodTo, string $currency, int $statementClosingBalance, ?int $createdBy): BankReconciliation
    {
        return BankReconciliation::firstOrCreate(
            ['period_from' => $periodFrom, 'period_to' => $periodTo, 'currency' => $currency],
            ['statement_closing_balance' => $statementClosingBalance, 'status' => 'draft', 'created_by' => $createdBy]
        );
    }

    /**
     * @return array{inserted: int, matched: int}
     */
    public function importStatement(BankReconciliation $recon, UploadedFile $file): array
    {
        abort_if($recon->status === 'complete', 422, 'This reconciliation is already complete — reopen it first.');

        $handle = fopen($file->getRealPath(), 'r');
        if (!$handle) {
            throw new InvalidArgumentException('Could not read the uploaded file.');
        }

        // Strip a UTF-8 BOM if present.
        $firstLine = fgets($handle);
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        $header = str_getcsv(trim($firstLine));
        $header = array_map(fn ($h) => strtolower(trim($h)), $header);

        $dateIdx = array_search('date', $header, true);
        $descIdx = array_search('description', $header, true);
        $debitIdx = array_search('debit', $header, true);
        $creditIdx = array_search('credit', $header, true);

        if ($dateIdx === false || $descIdx === false || $debitIdx === false || $creditIdx === false) {
            fclose($handle);
            throw new InvalidArgumentException('CSV must have Date, Description, Debit, Credit columns.');
        }

        $rows = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $cols = str_getcsv($line);

            $date = $this->parseDate($cols[$dateIdx] ?? null);
            if ($date === null) {
                continue;
            }

            $debit  = $this->parseAmount($cols[$debitIdx] ?? '0');
            $credit = $this->parseAmount($cols[$creditIdx] ?? '0');
            if ($debit === 0 && $credit === 0) {
                continue;
            }

            $rows[] = [
                'reconciliation_id' => $recon->id,
                'txn_date'    => $date,
                'description' => mb_substr(trim($cols[$descIdx] ?? ''), 0, 500),
                'debit'       => $debit,
                'credit'      => $credit,
                'created_at'  => now(),
            ];
        }
        fclose($handle);

        DB::transaction(function () use ($recon, $rows) {
            BankStatementLine::where('reconciliation_id', $recon->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                BankStatementLine::insert($chunk);
            }
        });

        $matched = $this->autoMatch($recon);

        return ['inserted' => count($rows), 'matched' => $matched];
    }

    public function clearStatement(BankReconciliation $recon): void
    {
        BankStatementLine::where('reconciliation_id', $recon->id)->delete();
    }

    /**
     * Matches unmatched statement lines against unmatched system-side bank
     * movements by exact amount within a ±N day window. Idempotent — already-
     * matched lines/candidates are skipped, so re-running just picks up new ones.
     */
    public function autoMatch(BankReconciliation $recon): int
    {
        $lines = $recon->lines()->get();
        $usedPaymentIds  = $lines->pluck('matched_payment_id')->filter()->all();
        $usedExpenseIds  = $lines->pluck('matched_expense_id')->filter()->all();
        $usedBillPayIds  = $lines->pluck('matched_vendor_bill_payment_id')->filter()->all();

        $creditCandidates = Payment::whereIn('payment_method', ['bank_transfer', 'cheque'])
            ->whereNotIn('id', $usedPaymentIds ?: [0])
            ->get(['id', 'amount', 'paid_at']);

        $expenseDebitCandidates = Expense::where('payment_mode', 'bank')
            ->whereNotIn('id', $usedExpenseIds ?: [0])
            ->get(['id', 'amount', 'tax_amount', 'expense_date']);

        $billPayDebitCandidates = VendorBillPayment::whereIn('payment_method', ['bank_transfer', 'cheque'])
            ->whereNotIn('id', $usedBillPayIds ?: [0])
            ->get(['id', 'amount', 'paid_at']);

        $matched = 0;

        foreach ($lines->whereNull('matched_payment_id')->whereNull('matched_expense_id')->whereNull('matched_vendor_bill_payment_id') as $line) {
            if ($line->credit > 0) {
                $hit = $creditCandidates->first(fn ($p) => $p->amount === $line->credit
                    && $this->withinWindow($p->paid_at, $line->txn_date));
                if ($hit) {
                    $line->update(['matched_payment_id' => $hit->id]);
                    $creditCandidates = $creditCandidates->reject(fn ($p) => $p->id === $hit->id);
                    $matched++;
                }
                continue;
            }

            if ($line->debit > 0) {
                $hit = $expenseDebitCandidates->first(fn ($e) => ($e->amount + $e->tax_amount) === $line->debit
                    && $this->withinWindow($e->expense_date, $line->txn_date));
                if ($hit) {
                    $line->update(['matched_expense_id' => $hit->id]);
                    $expenseDebitCandidates = $expenseDebitCandidates->reject(fn ($e) => $e->id === $hit->id);
                    $matched++;
                    continue;
                }

                $hit = $billPayDebitCandidates->first(fn ($p) => $p->amount === $line->debit
                    && $this->withinWindow($p->paid_at, $line->txn_date));
                if ($hit) {
                    $line->update(['matched_vendor_bill_payment_id' => $hit->id]);
                    $billPayDebitCandidates = $billPayDebitCandidates->reject(fn ($p) => $p->id === $hit->id);
                    $matched++;
                }
            }
        }

        return $matched;
    }

    public function match(BankStatementLine $line, string $type, int $matchId): void
    {
        abort_if(!in_array($type, ['payment', 'expense', 'vendor_bill_payment']), 422, 'Invalid match type.');

        $line->update([
            'matched_payment_id'             => $type === 'payment' ? $matchId : null,
            'matched_expense_id'             => $type === 'expense' ? $matchId : null,
            'matched_vendor_bill_payment_id' => $type === 'vendor_bill_payment' ? $matchId : null,
        ]);
    }

    public function unmatch(BankStatementLine $line): void
    {
        $line->update(['matched_payment_id' => null, 'matched_expense_id' => null, 'matched_vendor_bill_payment_id' => null]);
    }

    /**
     * @return array{sys_dr: int, sys_cr: int, cash_book_balance: int, unmatched_stmt_credit: int,
     *   unmatched_stmt_debit: int, reconciled_balance: int, statement_closing_balance: int, difference: int}
     */
    public function totals(BankReconciliation $recon): array
    {
        $sysDr = (int) Payment::whereIn('payment_method', ['bank_transfer', 'cheque'])
            ->whereBetween('paid_at', [$recon->period_from, $recon->period_to])
            ->sum('amount');

        $sysCrExpenses = (int) Expense::where('payment_mode', 'bank')
            ->whereBetween('expense_date', [$recon->period_from, $recon->period_to])
            ->selectRaw('COALESCE(SUM(amount + tax_amount), 0) as total')->value('total');

        $sysCrBills = (int) VendorBillPayment::whereIn('payment_method', ['bank_transfer', 'cheque'])
            ->whereBetween('paid_at', [$recon->period_from, $recon->period_to])
            ->sum('amount');

        $sysCr = $sysCrExpenses + $sysCrBills;
        $cashBookBalance = $sysDr - $sysCr;

        $unmatchedCredit = (int) $recon->lines()->whereNull('matched_payment_id')->sum('credit');
        $unmatchedDebit  = (int) $recon->lines()
            ->whereNull('matched_expense_id')->whereNull('matched_vendor_bill_payment_id')
            ->sum('debit');

        $reconciledBalance = $cashBookBalance + $unmatchedCredit - $unmatchedDebit;
        $difference = $reconciledBalance - $recon->statement_closing_balance;

        return [
            'sys_dr' => $sysDr, 'sys_cr' => $sysCr, 'cash_book_balance' => $cashBookBalance,
            'unmatched_stmt_credit' => $unmatchedCredit, 'unmatched_stmt_debit' => $unmatchedDebit,
            'reconciled_balance' => $reconciledBalance,
            'statement_closing_balance' => $recon->statement_closing_balance,
            'difference' => $difference,
        ];
    }

    public function complete(BankReconciliation $recon): void
    {
        $diff = $this->totals($recon)['difference'];
        abort_if($diff !== 0, 422, "Cannot complete — reconciliation is off by {$diff}. Match or investigate the remaining lines first.");

        $recon->update(['status' => 'complete']);
    }

    public function reopen(BankReconciliation $recon): void
    {
        $recon->update(['status' => 'draft']);
    }

    private function parseDate(?string $raw): ?string
    {
        if (!$raw || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $raw);
            $errors = \DateTime::getLastErrors();
            if ($date && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseAmount(string $raw): int
    {
        $clean = preg_replace('/[^0-9.\-]/', '', $raw);
        if ($clean === '' || $clean === null) {
            return 0;
        }
        return (int) round((float) $clean);
    }

    private function withinWindow(\DateTimeInterface|string $a, \DateTimeInterface|string $b): bool
    {
        $a = $a instanceof \DateTimeInterface ? $a : new \DateTime($a);
        $b = $b instanceof \DateTimeInterface ? $b : new \DateTime($b);
        $diffDays = abs($a->diff($b)->days);
        return $diffDays <= self::MATCH_WINDOW_DAYS;
    }
}
