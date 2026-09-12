<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Closes a real gap from the 2026-09-09 flow audit: resolving a service
// ticket never decided whether the repair was warranty-covered or should be
// billed — every ticket just resolved, with nobody's role responsible for
// remembering to check the machine's warranty and bill accordingly. Auto-
// decided at resolve() time from Machine::warranty_expiry, with a CTO/
// Director override (required reason) for judgment calls — goodwill repairs
// on an out-of-warranty machine, or a warranty claim the manufacturer
// rejected.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->string('billing_status', 20)->nullable()->after('resolved_at');
            // warranty_covered | billable | goodwill
            $table->foreignId('billing_decided_by')->nullable()->after('billing_status')->constrained('users')->nullOnDelete();
            $table->timestamp('billing_decided_at')->nullable()->after('billing_decided_by');
            $table->text('billing_override_reason')->nullable()->after('billing_decided_at');
            // Set once a real Invoice is generated for this ticket — no
            // create-invoice-from-ticket action exists yet (a separate,
            // still-open gap: resolving a ticket and invoicing it are two
            // disconnected actions today), but the column exists now so
            // overrideBilling() can lock the decision once that lands,
            // rather than adding it as a breaking change later.
            $table->foreignId('invoice_id')->nullable()->after('billing_override_reason')->constrained('invoices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_decided_by');
            $table->dropConstrainedForeignId('invoice_id');
            $table->dropColumn(['billing_status', 'billing_decided_at', 'billing_override_reason']);
        });
    }
};
