<?php

use App\Models\ExpenseCategory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Matches the two-level "cost of sales" breakdown in the user's notes —
// Inventory and Direct Expenses as reporting-tree parents over the
// existing cost-of-sales line items. Purely a parent_id change: every
// re-parented category keeps its own existing GL account (Importation
// Purchases still posts to 5014, etc.) — re-parenting doesn't touch
// account_id, so no ledger history is affected. The two new parents post
// to 5001 Cost of Goods Sold (the closest existing account) since a
// category row requires an account_id even though nobody is expected to
// post an expense directly against a parent-level grouping category.
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $cogsAccountId = ExpenseCategory::where('name', 'Cost of Goods Sold')->value('account_id');

            $inventory = ExpenseCategory::firstOrCreate(
                ['name' => 'Inventory', 'account_id' => $cogsAccountId],
                ['parent_id' => null],
            );
            $directExpenses = ExpenseCategory::firstOrCreate(
                ['name' => 'Direct Expenses', 'account_id' => $cogsAccountId],
                ['parent_id' => null],
            );

            ExpenseCategory::whereIn('name', ['Importation Purchases', 'Local Purchases'])
                ->update(['parent_id' => $inventory->id]);

            ExpenseCategory::whereIn('name', [
                'Clearing and Forwarding', 'TMDA Regulatory Fees', 'TBS Inspection Fees',
                'ZFDA Inspection Fees', 'Insurance', 'Warranty Expenses', 'Warehouse and Storage',
                'Site Preparations and Survey', 'Training for Radiographers',
                'Field Works and Per Diem Expenses', 'Carriage Outwards',
            ])->update(['parent_id' => $directExpenses->id]);
        });
    }

    public function down(): void
    {
        // Data-only reparenting — down() would need to know each row's
        // prior parent_id (null), which is safe to restore directly here
        // since these categories had no parent before this migration.
        ExpenseCategory::whereIn('name', [
            'Importation Purchases', 'Local Purchases',
            'Clearing and Forwarding', 'TMDA Regulatory Fees', 'TBS Inspection Fees',
            'ZFDA Inspection Fees', 'Insurance', 'Warranty Expenses', 'Warehouse and Storage',
            'Site Preparations and Survey', 'Training for Radiographers',
            'Field Works and Per Diem Expenses', 'Carriage Outwards',
        ])->update(['parent_id' => null]);

        ExpenseCategory::whereIn('name', ['Inventory', 'Direct Expenses'])
            ->whereDoesntHave('children')->delete();
    }
};
