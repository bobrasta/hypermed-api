<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TaskController::notifyManagers() has always inserted type => 'task', which
 * was never a valid value in notifications_type_check — every task
 * completion notification has been silently failing. Adds the two real task
 * lifecycle types (assigned, completed) needed to fix that and to notify an
 * assignee when a task is created for them.
 */
return new class extends Migration
{
    private const TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed',
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
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('service_due','ticket_assigned','ticket_updated','payment_overdue','warranty_expiring','deal_updated','system','lead_follow_up'))");
    }
};
