<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 19: tenders & contracts, board-resolution register, generated /
// executed tender documents, TMDA device registrations (with the Annex V
// essential-requirements checklist as rows), and a dedupe table for the
// escalating deadline reminders.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procuring_entities', function (Blueprint $t) {
            $t->id();
            $t->string('name');                       // "University of Dodoma"
            $t->string('addressee')->nullable();      // "Vice Chancellor"
            $t->text('address')->nullable();          // multi-line postal address
            $t->string('short_code', 20)->nullable(); // for our reference numbers (BMH)
            $t->string('contact')->nullable();
            $t->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('board_resolutions', function (Blueprint $t) {
            $t->id();
            $t->string('number', 50);
            $t->date('resolution_date');
            $t->string('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['number', 'resolution_date']);
        });

        Schema::create('tenders', function (Blueprint $t) {
            $t->id();
            $t->string('tender_number');              // as issued by the buyer, free text
            $t->string('contract_number')->nullable();
            $t->string('tender_type', 20)->default('standard'); // standard | framework
            $t->string('title');
            $t->foreignId('procuring_entity_id')->constrained()->restrictOnDelete();
            $t->bigInteger('estimated_value')->nullable();
            $t->bigInteger('contract_value')->nullable();
            $t->string('currency', 3)->default('TZS');
            $t->boolean('vat_inclusive')->default(false);
            $t->string('status', 40)->default('identified');
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('board_resolution_id')->nullable()->constrained()->nullOnDelete();
            $t->string('attorney_name')->nullable();
            $t->text('attorney_address')->nullable();
            $t->string('signatory_name')->nullable();
            $t->string('signatory_position')->nullable();
            $t->string('entity_ref')->nullable();      // their award-notification ref
            $t->date('entity_ref_date')->nullable();
            $t->string('our_ref')->nullable();         // our acceptance-letter ref
            $t->date('bid_submission_deadline')->nullable();
            $t->unsignedSmallInteger('bid_validity_days')->nullable();
            $t->date('tender_expiry_date')->nullable();
            $t->date('other_tenderer_notified_at')->nullable();
            $t->date('award_notified_at')->nullable();
            $t->date('letter_of_acceptance_date')->nullable();
            $t->string('performance_security_form', 20)->nullable(); // declaration | bank_guarantee
            $t->date('contract_signing_deadline')->nullable();
            $t->date('delivery_deadline')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('status');
        });
        DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_status_check CHECK (status IN ('identified','preparing_bid','bid_submitted','awaiting_award','won','lost','cancelled','accepted','performance_security_submitted','contract_signed','fulfilling','delivered','closed'))");

        Schema::create('tender_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tender_id')->constrained()->cascadeOnDelete();
            $t->string('type', 50);
            // Generated draft and executed (signed, scanned) copy are two
            // distinct files — a scan never overwrites the draft.
            $t->string('draft_path')->nullable();
            $t->timestamp('draft_generated_at')->nullable();
            $t->foreignId('draft_generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('executed_path')->nullable();
            $t->string('executed_original_name')->nullable();
            $t->timestamp('executed_uploaded_at')->nullable();
            $t->foreignId('executed_uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['tender_id', 'type']);
        });

        Schema::create('device_registrations', function (Blueprint $t) {
            $t->id();
            $t->string('brand_name');
            $t->string('common_name')->nullable();
            $t->string('model')->nullable();
            $t->string('manufacturer')->nullable();
            $t->string('risk_class', 5)->nullable();
            $t->string('registration_number')->nullable();
            $t->string('status', 30)->default('preparing_dossier');
            $t->date('submitted_at')->nullable();
            $t->date('registered_at')->nullable();
            $t->date('renewal_due_date')->nullable();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
        DB::statement("ALTER TABLE device_registrations ADD CONSTRAINT device_registrations_status_check CHECK (status IN ('preparing_dossier','submitted','under_review','registered','renewal_due','expired'))");

        Schema::create('device_requirements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_registration_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('principle_no');
            $t->string('principle');
            $t->boolean('applicable')->nullable();
            $t->string('method')->nullable();
            $t->string('supporting_document')->nullable();
            $t->timestamps();
            $t->unique(['device_registration_id', 'principle_no']);
        });

        Schema::create('device_registration_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_registration_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('original_name');
            $t->json('principle_nos')->nullable();  // which checklist principles it supports
            $t->string('description')->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('deadline_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('subject_type', 40);  // Tender | DeviceRegistration
            $t->unsignedBigInteger('subject_id');
            $t->string('kind', 40);
            $t->date('due_date');            // part of the key: moving a date re-arms its reminders
            $t->string('stage', 10);         // 7d | 3d | today | overdue
            $t->timestamp('sent_at');
            $t->unique(['subject_type', 'subject_id', 'kind', 'due_date', 'stage'], 'deadline_alerts_unique');
        });
    }

    public function down(): void
    {
        foreach (['deadline_alerts', 'device_registration_files', 'device_requirements', 'device_registrations',
                  'tender_documents', 'tenders', 'board_resolutions', 'procuring_entities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
