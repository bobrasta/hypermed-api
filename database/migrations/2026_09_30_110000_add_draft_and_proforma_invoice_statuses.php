<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Clickhuduma's sale Status: a sale can be saved as a Draft or a Proforma
// and finalised later. Both are invoices rows with their own numbering;
// they never touch the ledger or receivables (see Invoice's 'final' scope).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
            CHECK (status IN ('pending', 'partial', 'paid', 'overdue', 'waived', 'sent', 'cancelled', 'draft', 'proforma'))");

        $year = (int) now()->format('Y');
        foreach ([['sale_draft', 'Sale draft', 'DRAFT'], ['proforma', 'Proforma invoice', 'PRO']] as [$type, $label, $prefix]) {
            DB::table('document_sequences')->insertOrIgnore([
                'document_type' => $type, 'label' => $label, 'prefix' => $prefix, 'digits' => 4,
                'reset_yearly' => true, 'next_number' => 1, 'year' => $year,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('invoices')->whereIn('status', ['draft', 'proforma'])->delete();
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT invoices_status_check');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check
            CHECK (status IN ('pending', 'partial', 'paid', 'overdue', 'waived', 'sent', 'cancelled'))");
        DB::table('document_sequences')->whereIn('document_type', ['sale_draft', 'proforma'])->delete();
    }
};
