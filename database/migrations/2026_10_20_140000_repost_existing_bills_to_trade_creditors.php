<?php

use App\Models\CreditorAllocation;
use App\Models\VendorBill;
use App\Services\AccountingService;
use App\Services\Creditors\CreditorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Supplier invoices entered before the creditors work posted to 2001
// Accounts Payable with no TZS value recorded. Re-post each one whose
// currency matches its supplier's through CreditorService, so it lands on
// Trade Creditors International/Local and its supplier's ledger reconciles.
// An invoice in a different currency from its supplier (entered before
// currencies existed) is left alone — its true currency/rate needs a person.
return new class extends Migration
{
    public function up(): void
    {
        $bills = VendorBill::with('supplier')->where('status', '!=', 'cancelled')->where('total_tzs', 0)->get();
        foreach ($bills as $bill) {
            $supplierCurrency = strtoupper($bill->supplier?->currency ?: 'TZS');
            if (strtoupper($bill->currency) !== $supplierCurrency || CreditorAllocation::where('vendor_bill_id', $bill->id)->exists()) {
                continue;
            }
            DB::transaction(function () use ($bill) {
                app(AccountingService::class)->reverseByReference("BILL-{$bill->id}");
                app(CreditorService::class)->postBill($bill);
            });
        }
    }

    public function down(): void
    {
        // Not reversible: the old 2001 posting isn't recreated.
    }
};
