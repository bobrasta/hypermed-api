<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATUSES = [
        'draft', 'pending_sales_manager', 'pending_director_review',
        'pending_payment_initiation', 'pending_director_final', 'approved', 'rejected',
        'sent', 'acknowledged', 'partially_received', 'received', 'cancelled',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check');
        $list = "'" . implode("','", self::STATUSES) . "'";
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check');
        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check CHECK (status IN ('draft','sent','acknowledged','partially_received','received','cancelled'))");
    }
};
