<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
        'leave_requested', 'leave_approved', 'leave_rejected', 'late_arrival',
        'stock_out_requested', 'stock_out_approved', 'stock_out_rejected',
        'per_diem_requested', 'per_diem_forwarded', 'per_diem_approved', 'per_diem_rejected',
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
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('service_due','ticket_assigned','ticket_updated','payment_overdue','warranty_expiring','deal_updated','system','lead_follow_up','task_assigned','task_completed','stock_pull_required','leave_requested','leave_approved','leave_rejected','late_arrival','stock_out_requested','stock_out_approved','stock_out_rejected'))");
    }
};
