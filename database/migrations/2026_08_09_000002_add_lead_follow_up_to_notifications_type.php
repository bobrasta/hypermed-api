<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check
            CHECK (type IN ('service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
                             'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check
            CHECK (type IN ('service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
                             'warranty_expiring', 'deal_updated', 'system'))");
    }
};
