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

            // Assets — named bank accounts (real structure: multiple banks/currencies,
            // needed so each can be reconciled against its own statement individually
            // instead of one catch-all "Bank Account" balance).
            ['1008', "CRDB Bank 015'700 (TZS)", 'asset'],
            ['1009', "CRDB Bank 015'701 (TZS)", 'asset'],
            ['1010', "CRDB Bank 025'700 (TZS)", 'asset'],
            ['1011', "CRDB Bank 019'700 (EUR)", 'asset'],
            ['1012', "Equity Bank TZS 300'661", 'asset'],
            ['1013', "Equity Bank USD 300'452", 'asset'],
            ['1014', "Mwanga Bank TZS 000'250", 'asset'],
            ['1015', "NMB Bank TZS 000'056", 'asset'],
            ['1016', 'Property, Plant & Equipment', 'asset'],
            ['1017', 'Additional Assets (Capital WIP)', 'asset'],

            // Liabilities
            ['2001', 'Accounts Payable', 'liability'],
            ['2002', 'VAT Payable', 'liability'],
            ['2003', 'Loans Payable', 'liability'],

            // Liabilities — withholding tax, statutory, and bank-facility detail
            ['2004', 'Withholding Tax Payable — Rent (10%)', 'liability'],
            ['2005', 'Withholding Tax Payable — Services (5%)', 'liability'],
            ['2006', 'Withholding Tax Payable — Goods (2%)', 'liability'],
            ['2007', 'Accruals', 'liability'],
            ['2008', 'Social Security Funds Payable', 'liability'],
            ['2009', "Bank Overdraft — CRDB 015'700", 'liability'],
            ['2010', 'CRDB Accrued Loan Interest', 'liability'],
            ['2011', 'MHB Accrued Loan Interest', 'liability'],
            ['2012', 'NMB Accrued Loan Interest', 'liability'],
            ['2013', 'EXIM Accrued Loan Interest', 'liability'],
            ['2014', 'Corporate Income Tax Payable', 'liability'],

            // Equity
            ['3001', "Owner's Capital", 'equity'],
            ['3002', 'Retained Earnings', 'equity'],
            ['3003', 'Share Capital (Authorized)', 'equity'],
            ['3004', 'Advance Towards Share Capital', 'equity'],
            ['3005', 'Prior Year Tax Assessment (brought forward)', 'equity'],

            // Revenue
            ['4001', 'Equipment Sales Revenue', 'revenue'],
            ['4002', 'Consumables & Spare Parts Revenue', 'revenue'],
            ['4003', 'Service & Repair Revenue', 'revenue'],
            ['4004', 'Cancelled Sales', 'revenue'],

            // Expenses — original generic set
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

            // Expenses — direct costs (import/field-service operations)
            ['5014', 'Importation Purchases', 'expense'],
            ['5015', 'Local Purchases', 'expense'],
            ['5016', 'Clearing and Forwarding', 'expense'],
            ['5017', 'Carriage Outwards', 'expense'],
            ['5018', 'Field Works and Per Diem Expenses', 'expense'],
            ['5019', 'TMDA Regulatory Fees', 'expense'],
            ['5020', 'Training for Radiographers', 'expense'],
            ['5021', 'Site Preparations and Survey', 'expense'],
            ['5022', 'Warehouse and Storage', 'expense'],

            // Expenses — indirect/administrative costs
            ['5023', "Director's Fee", 'expense'],
            ['5024', 'Skills Development Levy (SDL)', 'expense'],
            ['5025', 'NSSF Contribution', 'expense'],
            ['5026', 'National Health Insurance Fund (NHIF)', 'expense'],
            ['5027', 'Commercial Rent', 'expense'],
            ['5028', 'Service Fees Rent', 'expense'],
            ['5029', 'Business Travel — Local', 'expense'],
            ['5030', 'Business Travel — International', 'expense'],
            ['5031', 'Conferences & Business Meetings — Local', 'expense'],
            ['5032', 'Seminar and Workshop — Abroad', 'expense'],
            ['5033', 'Seminar and Workshop — Local', 'expense'],
            ['5034', 'Communication, Fax and Postage', 'expense'],
            ['5035', 'Security Services', 'expense'],
            ['5036', 'OSHA & Safety', 'expense'],
            ['5037', 'Business Licence', 'expense'],
            ['5038', 'Membership and Subscriptions', 'expense'],
            ['5039', 'Corporate Social Responsibility', 'expense'],
            ['5040', 'Staff Uniform', 'expense'],
            ['5041', 'Office Lunch', 'expense'],

            // Expenses — taxation
            ['5042', 'Provision for Corporate Income Tax', 'expense'],
        ];

        // Foreign-currency bank accounts — everything else defaults to TZS.
        $currencyOverrides = [
            '1011' => 'EUR', // CRDB Bank 019'700
            '1013' => 'USD', // Equity Bank 300'452
        ];

        foreach ($accounts as [$code, $name, $type]) {
            ChartOfAccount::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'category_id' => $categoryIds[$type], 'currency' => $currencyOverrides[$code] ?? 'TZS']
            );
        }
    }
}
