<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Meetings register: the Administrator (secretary to every meeting) records
// attendance, minutes and decisions; action items become real tasks on the
// owner's board, so marking the task done shows on the minutes. Attendees
// read the minutes of their own meetings once circulated; meetings.manage
// holders (Administrator, Director, CTO) see every meeting.
return new class extends Migration
{
    // Latest list from 2026_09_27_130000 plus the meeting types.
    private const NOTIFICATION_TYPES = [
        'service_due', 'ticket_assigned', 'ticket_updated', 'payment_overdue',
        'warranty_expiring', 'deal_updated', 'system', 'lead_follow_up',
        'task_assigned', 'task_completed', 'stock_pull_required',
        'leave_requested', 'leave_approved', 'leave_rejected', 'late_arrival',
        'stock_out_requested', 'stock_out_approved', 'stock_out_rejected',
        'per_diem_requested', 'per_diem_forwarded', 'per_diem_approved', 'per_diem_rejected',
        'expense_requested', 'expense_escalated', 'expense_approved', 'expense_rejected',
        'po_submitted', 'po_sales_approved', 'po_director_reviewed', 'po_payment_initiated',
        'po_approved', 'po_rejected', 'low_stock_alert', 'per_diem_paid', 'hr_alert',
        'expense_paid', 'ticket_billing_overridden',
        'vendor_fee_ready_for_payment', 'vendor_fee_paid', 'vendor_fee_rejected',
        'tender_deadline', 'tender_overdue', 'device_renewal',
        'shipment_created', 'shipment_status',
    ];

    private const MEETING_TYPES = ['meeting_scheduled', 'meeting_minutes'];

    private const TEMPLATES = [
        ['meeting.scheduled', 'meeting_scheduled', 'Meeting: {title}',
            '{organiser} invited you to "{title}" on {when}{where_suffix}.',
            'Every staff attendee — a meeting was scheduled or they were added to one'],
        ['meeting.minutes_circulated', 'meeting_minutes', 'Minutes: {title}',
            'The minutes of "{title}" ({date}) are ready to read.',
            'Every staff attendee — the secretary circulated the minutes'],
        ['meeting.action_item', 'task_assigned', 'Action item: {meeting}',
            'From "{meeting}": {description}{due_suffix}',
            'The owner of a meeting action item (it is also on their task board)'],
    ];

    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('category')->default('general');
            $t->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at')->nullable();
            $t->string('location')->nullable();
            $t->string('online_link')->nullable();
            $t->foreignId('chaired_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('agenda')->nullable();
            $t->text('minutes')->nullable();
            $t->text('decisions')->nullable();
            $t->string('status')->default('scheduled');
            $t->timestamp('circulated_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('starts_at');
        });
        DB::statement("ALTER TABLE meetings ADD CONSTRAINT meetings_status_check CHECK (status IN ('scheduled','held','minutes_final','circulated','cancelled'))");

        Schema::create('meeting_attendees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('guest_name')->nullable();
            $t->string('guest_organisation')->nullable();
            $t->string('guest_email')->nullable();
            $t->string('attendance')->default('invited');
            $t->timestamps();
            $t->unique(['meeting_id', 'user_id']);
        });
        DB::statement("ALTER TABLE meeting_attendees ADD CONSTRAINT meeting_attendees_attendance_check CHECK (attendance IN ('invited','present','absent','apologies'))");

        Schema::create('meeting_action_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->text('description');
            $t->foreignId('owner_id')->constrained('users');
            $t->date('due_date')->nullable();
            $t->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('meeting_attachments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained()->cascadeOnDelete();
            $t->string('original_name');
            $t->string('stored_name');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        $this->setNotificationTypes([...self::NOTIFICATION_TYPES, ...self::MEETING_TYPES]);

        foreach (self::TEMPLATES as [$key, $type, $title, $body, $description]) {
            DB::table('notification_templates')->insertOrIgnore([
                'template_key' => $key, 'notification_type' => $type, 'title_template' => $title,
                'body_template' => $body, 'description' => $description, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('template_key', array_column(self::TEMPLATES, 0))->delete();
        DB::table('notifications')->whereIn('type', self::MEETING_TYPES)->delete();
        DB::table('notifications')->where('entity_type', 'meeting')->delete();
        $this->setNotificationTypes(self::NOTIFICATION_TYPES);
        foreach (['meeting_attachments', 'meeting_action_items', 'meeting_attendees', 'meetings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function setNotificationTypes(array $types): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT notifications_type_check');
        $list = "'" . implode("','", $types) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }
};
