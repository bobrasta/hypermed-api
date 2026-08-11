<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only path through which chart_of_accounts.balance / transactions are
 * ever written. Every posting is validated as balanced (debits = credits)
 * before anything touches the database — this is the actual double-entry
 * guarantee, not just a naming convention.
 *
 * Callers never mutate a posted transaction. To correct or void a posting,
 * call reverseByReference() first (typically inside the same DB transaction
 * as the source record's own edit/delete), then post a fresh one if needed.
 */
class AccountingService
{
    private const NORMAL_BALANCE = [
        'asset'     => 'debit',
        'expense'   => 'debit',
        'liability' => 'credit',
        'equity'    => 'credit',
        'revenue'   => 'credit',
    ];

    private array $accountIdCache = [];

    /**
     * @param array<int, array{account_id: int, type: 'debit'|'credit', amount: int}> $entries
     * @return Transaction[]
     */
    public function recordTransaction(array $entries, string $description, ?string $reference = null, ?string $date = null): array
    {
        if (count($entries) < 2) {
            throw new InvalidArgumentException('A transaction needs at least two legs.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($entries as $entry) {
            if (($entry['amount'] ?? 0) <= 0) {
                throw new InvalidArgumentException('Each leg amount must be greater than zero.');
            }
            if (!in_array($entry['type'] ?? null, ['debit', 'credit'], true)) {
                throw new InvalidArgumentException('Each leg type must be debit or credit.');
            }

            if ($entry['type'] === 'debit') {
                $totalDebits += $entry['amount'];
            } else {
                $totalCredits += $entry['amount'];
            }
        }

        if ($totalDebits !== $totalCredits) {
            throw new InvalidArgumentException(
                "Unbalanced transaction: debits ({$totalDebits}) != credits ({$totalCredits})."
            );
        }

        return DB::transaction(function () use ($entries, $description, $reference, $date) {
            $created = [];

            foreach ($entries as $entry) {
                $account = ChartOfAccount::whereKey($entry['account_id'])->lockForUpdate()->first();

                if (!$account) {
                    throw new InvalidArgumentException("Account {$entry['account_id']} does not exist.");
                }
                if ($account->status !== 'active') {
                    throw new InvalidArgumentException("Account {$account->code} ({$account->name}) is inactive.");
                }

                $normalBalance = self::NORMAL_BALANCE[$account->category->type];
                $delta = $entry['type'] === $normalBalance ? $entry['amount'] : -$entry['amount'];

                $account->increment('balance', $delta);

                $created[] = Transaction::create([
                    'account_id'  => $account->id,
                    'type'        => $entry['type'],
                    'amount'      => $entry['amount'],
                    'description' => $entry['description'] ?? $description,
                    'reference'   => $reference,
                    'created_at'  => $date ?? now(),
                ]);
            }

            return $created;
        });
    }

    /**
     * Reverses every leg posted under $reference: undoes each account's
     * balance delta, then deletes the rows. Safe to call even if $reference
     * has no postings (no-op).
     */
    public function reverseByReference(string $reference): void
    {
        DB::transaction(function () use ($reference) {
            $legs = Transaction::where('reference', $reference)->lockForUpdate()->get();

            foreach ($legs as $leg) {
                $account = ChartOfAccount::whereKey($leg->account_id)->lockForUpdate()->first();
                if (!$account) {
                    continue;
                }

                $normalBalance = self::NORMAL_BALANCE[$account->category->type];
                $delta = $leg->type === $normalBalance ? -$leg->amount : $leg->amount;
                $account->increment('balance', $delta);
            }

            Transaction::where('reference', $reference)->delete();
        });
    }

    public function accountIdByCode(string $code): int
    {
        if (isset($this->accountIdCache[$code])) {
            return $this->accountIdCache[$code];
        }

        $id = ChartOfAccount::where('code', $code)->value('id');

        if ($id === null) {
            throw new InvalidArgumentException("No chart of accounts entry with code {$code}.");
        }

        return $this->accountIdCache[$code] = $id;
    }
}
