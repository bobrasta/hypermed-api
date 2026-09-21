<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 8: "A technician cannot edit by default. The CTO grants edit
 * permission on a specific plan, for the whole plan or specific days, with
 * an optional expiry, and can revoke it at any time."
 *
 * scope null = the whole plan; otherwise an array of per_diem_lines.seq_no
 * values the technician may propose changes to. v1 records scope for audit/
 * display but enforces only "has an active grant at all" — granular
 * per-day enforcement is a documented follow-up, not the core safety
 * property (a technician's edit never applies without CTO review either way).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('per_diem_edit_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('per_diem_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technician_id')->constrained('users');
            $table->foreignId('granted_by')->constrained('users');
            $table->json('scope')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('per_diem_edit_grants');
    }
};
