<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // PostgreSQL: make hospital_id nullable without touching the FK constraint
        DB::statement('ALTER TABLE invoices ALTER COLUMN hospital_id DROP NOT NULL');

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')
                  ->nullable()
                  ->constrained('sales_orders')
                  ->nullOnDelete();

            $table->string('client_name')->nullable();
            $table->string('client_contact')->nullable();
            $table->string('client_email')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['sales_order_id']);
            $table->dropColumn(['sales_order_id', 'client_name', 'client_contact', 'client_email']);
        });

        DB::statement('ALTER TABLE invoices ALTER COLUMN hospital_id SET NOT NULL');
    }
};
