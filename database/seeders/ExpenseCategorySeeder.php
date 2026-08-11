<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        // One category per manually-loggable expense GL account.
        // 5001 (Cost of Goods Sold) is excluded — that's system-computed, not a category
        // a user picks when logging an expense.
        $categories = [
            'Salaries & Wages'            => '5002',
            'Rent'                        => '5003',
            'Utilities'                   => '5004',
            'Internet & Phone'            => '5005',
            'Fuel & Transport'            => '5006',
            'Marketing & Advertising'     => '5007',
            'Office Supplies'             => '5008',
            'Repairs & Maintenance'       => '5009',
            'Miscellaneous'               => '5010',
            'Insurance'                   => '5011',
            'Bank Charges'                => '5012',
            'Government Fees & Licenses'  => '5013',
        ];

        foreach ($categories as $name => $accountCode) {
            $accountId = ChartOfAccount::where('code', $accountCode)->value('id');

            ExpenseCategory::firstOrCreate(
                ['name' => $name],
                ['account_id' => $accountId]
            );
        }
    }
}
