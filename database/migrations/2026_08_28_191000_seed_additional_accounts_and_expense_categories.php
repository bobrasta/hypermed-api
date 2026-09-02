<?php

use App\Models\AccountCategory;
use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Fills real gaps against the existing (already substantial) chart of
// accounts — new banks, missing liability/expense lines, and subcategory
// breakdowns — sourced from the user's own accounting notes. Idempotent
// (firstOrCreate by code/name) so re-running is harmless. Subcategories
// share their parent's GL account: parent_id is a reporting tree, not a
// second ledger dimension, so this doesn't explode the chart of accounts.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $assetId     = AccountCategory::where('type', 'asset')->value('id');
            $liabilityId = AccountCategory::where('type', 'liability')->value('id');
            $expenseId   = AccountCategory::where('type', 'expense')->value('id');

            $account = fn (string $code, string $name, int $categoryId) =>
                ChartOfAccount::firstOrCreate(['code' => $code], [
                    'name' => $name, 'category_id' => $categoryId, 'currency' => 'TZS', 'balance' => 0, 'status' => 'active',
                ]);

            // ── New bank accounts (assets) ──────────────────────────────
            $account('1018', 'EXIM Bank TZS', $assetId);
            $account('1019', 'TCB Bank TZS', $assetId);
            $account('1020', 'NCBA Bank TZS', $assetId);
            $account('1021', 'DTB Bank TZS', $assetId);
            $account('1022', 'KCB Bank TZS', $assetId);

            // ── New prepayment accounts (assets) ────────────────────────
            $account('1023', 'Salary Advances', $assetId);
            $account('1024', 'Staff Loans Receivable', $assetId);

            // ── New bank overdraft / accrued-interest liabilities ───────
            $account('2015', 'Bank Overdraft — TCB', $liabilityId);
            $account('2016', 'Bank Overdraft — NCBA', $liabilityId);
            $account('2017', 'Bank Overdraft — DTB', $liabilityId);
            $account('2018', 'Bank Overdraft — KCB', $liabilityId);
            $account('2019', 'Bank Overdraft — EXIM', $liabilityId);
            $account('2020', 'TCB Accrued Loan Interest', $liabilityId);
            $account('2021', 'NCBA Accrued Loan Interest', $liabilityId);
            $account('2022', 'DTB Accrued Loan Interest', $liabilityId);
            $account('2023', 'KCB Accrued Loan Interest', $liabilityId);

            // ── New accrual liabilities ──────────────────────────────────
            $account('2024', 'Trade Creditors — International', $liabilityId);
            $account('2025', 'Trade Creditors — Local', $liabilityId);
            $account('2026', 'Audit Fee Payable', $liabilityId);
            $account('2027', 'Withholding Tax Payable — Directors\' Fees', $liabilityId);
            $account('2028', 'City Levy Payable', $liabilityId);
            $account('2029', 'Short-Term Financing Facilities', $liabilityId);

            // ── New expense accounts ─────────────────────────────────────
            $newExpenseAccounts = [
                '5043' => 'Printing & Stationery',
                '5044' => 'Accounting & Audit Fees',
                '5045' => 'Cleaning & Sanitation',
                '5046' => 'Stamp Duty',
                '5047' => 'BRELA & Company Registration',
                '5048' => 'Warranty Expenses',
                '5049' => 'TBS Inspection Fees',
                '5050' => 'ZFDA Inspection Fees',
                '5051' => 'Bank Guarantee Fees',
                '5052' => 'Performance Bond Premiums',
                '5053' => 'Loan Application & Processing Fees',
                '5054' => 'Mobile Money Transfer Charges',
                '5055' => 'Overdraft Fees & Interest',
                '5056' => 'Short-Term Financing Interest & Costs',
                "5057" => "Director's Expenses",
                '5058' => 'Workers Compensation Fund (WCF)',
            ];
            foreach ($newExpenseAccounts as $code => $name) { $account($code, $name, $expenseId); }

            // ── Expense categories (top-level) for every expense account
            //    that doesn't already have one — covers the new accounts
            //    above plus the one pre-existing orphan (Field Works). ───
            $accountsNeedingCategory = ChartOfAccount::where('category_id', $expenseId)
                ->whereDoesntHave('expenseCategories')
                ->get();
            $category = fn (string $name, int $accountId, ?int $parentId = null) =>
                ExpenseCategory::firstOrCreate(['name' => $name, 'account_id' => $accountId], ['parent_id' => $parentId]);

            $catByAccountCode = [];
            foreach ($accountsNeedingCategory as $acc) {
                $catByAccountCode[$acc->code] = $category($acc->name, $acc->id);
            }
            // Also index the pre-existing category rows we need as parents below.
            $existingByName = ExpenseCategory::whereIn('name', ['Membership and Subscriptions', 'Bank Charges'])
                ->get()->keyBy('name');

            // ── Subcategories: Membership and Subscriptions ─────────────
            if ($parent = $existingByName['Membership and Subscriptions'] ?? null) {
                foreach ([
                    'TARA Subscription', 'MELSAT Annual Subscription', 'AMNETT Exhibition',
                    'Tanzania Association of Dentist Contributions', 'Other Contributions',
                ] as $name) {
                    $category($name, $parent->account_id, $parent->id);
                }
            }

            // ── Subcategories: Bank Charges, per bank × currency ─────────
            if ($parent = $existingByName['Bank Charges'] ?? null) {
                foreach ([
                    'CRDB TZS1', 'CRDB TZS2', 'CRDB USD', 'CRDB EUR',
                    'NMB TZS', 'NMB USD', 'Equity TZS', 'Equity USD',
                    'MHB TZS', 'MHB USD', 'TCB TZS', 'TCB USD',
                    'NCBA TZS', 'NCBA USD', 'EXIM TZS', 'EXIM USD',
                    'DTB TZS', 'DTB USD', 'KCB TZS', 'KCB USD',
                ] as $bank) {
                    $category("Bank Charges — $bank", $parent->account_id, $parent->id);
                }
            }

            // ── Subcategories: per-bank breakdown for the new finance-cost
            //    categories (guarantee fees, bond premiums, loan fees,
            //    overdraft fees, short-term financing interest) ──────────
            $perBank = ['EXIM', 'CRDB', 'NMB', 'MHB', 'TCB', 'NCBA'];
            foreach ([
                '5051' => 'Bank Guarantee Fees',
                '5052' => 'Performance Bond Premiums',
                '5053' => 'Loan Application & Processing Fees',
                '5055' => 'Overdraft Fees & Interest',
                '5056' => 'Short-Term Financing Interest & Costs',
            ] as $code => $label) {
                $parent = $catByAccountCode[$code] ?? null;
                if (! $parent) continue;
                foreach ($perBank as $bank) {
                    $category("$label — $bank", $parent->account_id, $parent->id);
                }
            }

            // ── Mobile Money Transfer Charges — single real sub-line ────
            if ($parent = $catByAccountCode['5054'] ?? null) {
                $category('Mobile Money Transfer Charges — MPESA 5310661', $parent->account_id, $parent->id);
            }
        });
    }

    public function down(): void
    {
        // Data-only seed — intentionally not reversed automatically, since
        // by the time this would roll back the new accounts/categories may
        // already carry real postings. Remove by hand via the chart of
        // accounts UI if genuinely needed.
    }
};
