<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 8: "The original release is never rewritten. If amounts change
 * after 'Money is Out', record an adjustment (extra amount to send, or
 * amount to return) visible to the CTO and finance."
 *
 * amount is signed: positive = extra to send the technician, negative =
 * amount to recover from them. Visibility-only per spec (no settle/close
 * workflow asked for) — finance sees it on the plan, nothing more built.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('per_diem_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('per_diem_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('per_diem_revision_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount');
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('per_diem_adjustments');
    }
};
