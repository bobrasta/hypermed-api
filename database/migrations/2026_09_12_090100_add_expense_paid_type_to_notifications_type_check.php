<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Mirrors 2026_08_25_090900_add_per_diem_paid_type_to_notifications_type_check.php
// for the new Expense payment-release step (see
// 2026_09_12_090000_add_payment_release_to_expenses_table.php).
return new class extends Migration
{
    private const TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
        'leave_requested', 'leave_approved', 'leave_rejected', 'late_arrival',
        'stock_out_requested', 'stock_out_approved', 'stock_out_rejected',
        'per_diem_requested', 'per_diem_forwarded', 'per_diem_approved', 'per_diem_rejected',
        'expense_requested', 'expense_escalated', 'expense_approved', 'expense_rejected',
        'po_submitted', 'po_sales_approved', 'po_director_reviewed', 'po_payment_initiated',
        'po_approved', 'po_rejected', 'low_stock_alert', 'per_diem_paid', 'hr_alert',
        'expense_paid',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        $list = "'" . implode("','", self::TYPES) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }

    public function down(): void
    {
        $prior = array_values(array_diff(self::TYPES, ['expense_paid']));
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        $list = "'" . implode("','", $prior) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }
};
