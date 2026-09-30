<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Clickhuduma-style sale record ("All sales"): who added the sale, a staff
// note, and the Edit Shipping fields.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('notes')->constrained('users')->nullOnDelete();
            // Seller's name for imported sales whose seller has no account here.
            $table->string('added_by_name')->nullable()->after('created_by');
            $table->text('staff_note')->nullable()->after('added_by_name');
            $table->string('shipping_status', 20)->nullable()->after('shipping_charges');
            $table->text('shipping_address')->nullable()->after('shipping_status');
            $table->text('shipping_details')->nullable()->after('shipping_address');
            $table->string('delivered_to')->nullable()->after('shipping_details');
        });

        // Who created each existing invoice, from the audit trail.
        DB::statement(<<<'SQL'
            update invoices i set created_by = a.causer_id
            from (
                select distinct on (subject_id) subject_id, causer_id
                from activity_log
                where subject_type = 'App\Models\Invoice' and event = 'created'
                  and causer_type = 'App\Models\User' and causer_id is not null
                order by subject_id, id
            ) a
            where a.subject_id = i.id and i.created_by is null
              and exists (select 1 from users u where u.id = a.causer_id)
            SQL);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['added_by_name', 'staff_note', 'shipping_status', 'shipping_address', 'shipping_details', 'delivered_to']);
        });
    }
};
