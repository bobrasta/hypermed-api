<?php

namespace Database\Seeders;

use App\Models\AccountCategory;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $categoryIds = [];
        foreach (['asset', 'liability', 'equity', 'revenue', 'expense'] as $type) {
            $categoryIds[$type] = AccountCategory::firstOrCreate(['type' => $type])->id;
        }

        $accounts = [
            // Assets
            ['1001', 'Cash on Hand', 'asset'],
            ['1002', 'Bank Account', 'asset'],
            ['1004', 'Mobile Money', 'asset'],
            ['1005', 'Accounts Receivable', 'asset'],
            ['1006', 'Inventory', 'asset'],
            ['1007', 'Prepaid Expenses', 'asset'],

            // Liabilities
            ['2001', 'Accounts Payable', 'liability'],
            ['2002', 'VAT Payable', 'liability'],
            ['2003', 'Loans Payable', 'liability'],

            // Equity
            ['3001', "Owner's Capital", 'equity'],
            ['3002', 'Retained Earnings', 'equity'],

            // Revenue
            ['4001', 'Equipment Sales Revenue', 'revenue'],
            ['4002', 'Consumables & Spare Parts Revenue', 'revenue'],
            ['4003', 'Service & Repair Revenue', 'revenue'],

            // Expenses
            ['5001', 'Cost of Goods Sold', 'expense'],
            ['5002', 'Salaries & Wages', 'expense'],
            ['5003', 'Rent Expense', 'expense'],
            ['5004', 'Utilities (Electricity & Water)', 'expense'],
            ['5005', 'Internet & Phone', 'expense'],
            ['5006', 'Fuel & Transport', 'expense'],
            ['5007', 'Marketing & Advertising', 'expense'],
            ['5008', 'Office Supplies', 'expense'],
            ['5009', 'Repairs & Maintenance', 'expense'],
            ['5010', 'Miscellaneous Expense', 'expense'],
            ['5011', 'Insurance Expense', 'expense'],
            ['5012', 'Bank Charges', 'expense'],
            ['5013', 'Government Fees & Licenses', 'expense'],
        ];

        foreach ($accounts as [$code, $name, $type]) {
            ChartOfAccount::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'category_id' => $categoryIds[$type], 'currency' => 'TZS']
            );
        }
    }
}
