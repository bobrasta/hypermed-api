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
        // a user picks when logging an expense. 5018 (Field Works and Per Diem) is also
        // excluded — that's posted via the Travel Plan / per-diem approval flow, not
        // logged as a generic expense.
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

            // Real categories from the 2024 statutory ledgers
            'Clearing and Forwarding'                => '5016',
            'Carriage Outwards'                       => '5017',
            'TMDA Regulatory Fees'                     => '5019',
            'Training for Radiographers'               => '5020',
            'Site Preparations and Survey'             => '5021',
            'Warehouse and Storage'                    => '5022',
            "Director's Fee"                           => '5023',
            'Skills Development Levy (SDL)'            => '5024',
            'NSSF Contribution'                        => '5025',
            'National Health Insurance Fund (NHIF)'    => '5026',
            'Commercial Rent'                          => '5027',
            'Service Fees Rent'                        => '5028',
            'Business Travel — Local'                  => '5029',
            'Business Travel — International'          => '5030',
            'Conferences & Business Meetings — Local'  => '5031',
            'Seminar and Workshop — Abroad'             => '5032',
            'Seminar and Workshop — Local'              => '5033',
            'Communication, Fax and Postage'            => '5034',
            'Security Services'                         => '5035',
            'OSHA & Safety'                             => '5036',
            'Business Licence'                          => '5037',
            'Membership and Subscriptions'              => '5038',
            'Corporate Social Responsibility'           => '5039',
            'Staff Uniform'                             => '5040',
            'Office Lunch'                              => '5041',
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
