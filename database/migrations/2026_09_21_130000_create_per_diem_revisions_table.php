<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 8 of hypermed_claude_code_prompt.md: "Every edit creates a
 * revision: who, what changed, old and new values, time, reason."
 *
 * Doubles as the technician-edit workflow's queue: a technician's proposed
 * edit is stored here with status='pending_cto_approval' and no `after`
 * snapshot yet — "it goes back to the CTO for approval, and until approved
 * the plan keeps its previous approved values." Approving/rejecting updates
 * this same row rather than creating a second one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('per_diem_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('per_diem_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('edited_by')->constrained('users');
            $table->string('editor_role'); // cto | technician
            $table->string('status')->default('applied'); // applied | pending_cto_approval | rejected
            $table->text('reason');
            // Full lines+amount snapshot before the edit; always present.
            $table->json('before');
            // Snapshot after the edit — null while a technician's proposal
            // is still pending_cto_approval.
            $table->json('after')->nullable();
            // For a technician-submitted edit: the lines/from_seq_no they
            // proposed, applied verbatim on CTO approval (not re-entered).
            $table->json('proposed')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('per_diem_revisions');
    }
};
