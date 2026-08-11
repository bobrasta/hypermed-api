<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New notification type for storekeepers: fired when a sales order is
 * confirmed, so stock can be pulled and readied for delivery — no prior
 * notification told storekeepers a confirmed order was waiting on them.
 */
return new class extends Migration
{
    private const TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        $list = "'" . implode("','", self::TYPES) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('service_due','ticket_assigned','ticket_updated','payment_overdue','warranty_expiring','deal_updated','system','lead_follow_up','task_assigned','task_completed'))");
    }
};
