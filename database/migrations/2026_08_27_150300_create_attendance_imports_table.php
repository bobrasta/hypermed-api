<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_imports', function (Blueprint $table) {
            $table->id();
            $table->string('filename');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            // Rows the flexible header-matcher couldn't confidently map to a
            // staff member/date — surfaced for HR to fix and re-import, not
            // silently dropped. No confirmed real HIK export sample was
            // available to lock an exact column layout against, so the
            // parser matches by header-name variants rather than fixed
            // column positions; this is where that uncertainty surfaces.
            $table->json('unmatched_rows')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_imports');
    }
};
