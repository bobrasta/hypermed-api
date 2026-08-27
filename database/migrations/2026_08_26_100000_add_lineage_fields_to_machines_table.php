<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chain-of-custody for individually tracked equipment: which sale it went
 * out on, who installed it, and who confirmed the install. Requested
 * 2026-08-26 while working the HR/Finance plan — the delivery flow already
 * consumes SerialNumber units one-by-one into a Machine record
 * (MachineRegistrationService), but that Machine record previously had no
 * link back to the sale and was marked 'operational' the instant it shipped,
 * with no installer/sign-off capture at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->after('hospital_id')
                ->constrained('sales_orders')->nullOnDelete();
            $table->foreignId('installation_ticket_id')->nullable()->after('sales_order_id')
                ->constrained('service_tickets')->nullOnDelete();
            $table->foreignId('installed_by')->nullable()->after('installation_ticket_id')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable()->after('installed_by');
            $table->foreignId('signed_off_by')->nullable()->after('installed_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('signed_off_at')->nullable()->after('signed_off_by');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sales_order_id');
            $table->dropConstrainedForeignId('installation_ticket_id');
            $table->dropConstrainedForeignId('installed_by');
            $table->dropColumn('installed_at');
            $table->dropConstrainedForeignId('signed_off_by');
            $table->dropColumn('signed_off_at');
        });
    }
};
