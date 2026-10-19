<?php

use Database\Seeders\NestRelevanceRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NeST tender tracker: tenders pulled from nest.go.tz's public listing,
// their change history, and the editable relevance rules (seeded here so
// production gets them on deploy). A NeST tender the team decides to bid on
// is linked to a regular Section 19 tender (tender_id) and follows that
// tender's status from then on.
return new class extends Migration
{
    private const PRIOR_TYPES = [
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
        'meeting_scheduled', 'meeting_minutes',
    ];

    private const NEST_TYPES = ['nest_tender_new', 'nest_tender_changed', 'nest_tender_digest'];

    public function up(): void
    {
        Schema::create('nest_tenders', function (Blueprint $t) {
            $t->id();
            $t->uuid('entity_uuid')->unique();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('entity_number')->nullable();
            $t->string('reference_number')->nullable();
            $t->string('entity_type', 40)->nullable();       // TENDER, FRAMEWORK, PLANNED_TENDER
            $t->string('entity_status', 40)->nullable();
            $t->text('description');
            $t->string('sub_category_acronym', 120)->nullable();
            $t->string('sub_category_name')->nullable();
            $t->string('procurement_category', 10)->nullable(); // G, W, NC, C
            $t->string('procurement_category_name', 60)->nullable();
            $t->string('procuring_entity_name')->nullable();
            $t->uuid('procuring_entity_uuid')->nullable();
            $t->string('financial_year', 20)->nullable();
            $t->unsignedInteger('lot_count')->nullable();
            $t->boolean('has_addendum')->default(false);
            $t->text('eligible_types')->nullable();
            $t->timestamp('invitation_at')->nullable();
            $t->timestamp('closing_at')->nullable();
            $t->integer('relevance_score')->default(0);
            $t->boolean('is_relevant')->default(false);
            $t->json('tags')->nullable();
            $t->json('matched_keywords')->nullable();
            // Triage before a bid starts; once tender_id is set the linked
            // tender's status is the pipeline.
            $t->string('triage_status', 20)->default('new'); // new | reviewing | skipped
            $t->text('notes')->nullable();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('tender_id')->nullable()->constrained('tenders')->nullOnDelete();
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->json('raw')->nullable();
            $t->timestamps();

            $t->index('closing_at');
            $t->index('is_relevant');
            $t->index('procuring_entity_name');
            $t->index('procuring_entity_uuid');
            $t->index('sub_category_acronym');
            $t->index('triage_status');
        });

        Schema::create('nest_tender_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('nest_tender_id')->constrained('nest_tenders')->cascadeOnDelete();
            $t->string('field', 40);   // closing_at | has_addendum | lot_count | description
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
            $t->timestamp('detected_at');
            $t->index(['nest_tender_id', 'detected_at']);
        });

        Schema::create('nest_relevance_rules', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30);               // keyword | exclude | health_subcategory | entity_hint
            $t->string('tag', 20)->nullable();    // DEVICES | REAGENTS | CONSUMABLES | SERVICE
            $t->string('term');
            $t->integer('weight');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['type', 'tag', 'term']);
        });

        Schema::create('nest_sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('trigger', 20)->default('schedule'); // schedule | manual | cli
            $t->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status', 20)->default('running');  // running | ok | failed
            $t->unsignedInteger('pages')->default(0);
            $t->unsignedInteger('records')->default(0);
            $t->unsignedInteger('new_count')->default(0);
            $t->unsignedInteger('new_relevant')->default(0);
            $t->unsignedInteger('changes')->default(0);
            $t->text('error')->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->index('started_at');
        });

        (new NestRelevanceRuleSeeder)->run();

        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes([...self::PRIOR_TYPES, ...self::NEST_TYPES]);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::table('notifications')->whereIn('type', self::NEST_TYPES)->delete();
            $this->setNotificationTypes(self::PRIOR_TYPES);
        }
        foreach (['nest_sync_runs', 'nest_relevance_rules', 'nest_tender_changes', 'nest_tenders'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function setNotificationTypes(array $types): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        $list = "'" . implode("','", $types) . "'";
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ($list))");
    }
};
