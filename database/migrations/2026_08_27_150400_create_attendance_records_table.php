<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('clock_in')->nullable();
            $table->time('clock_out')->nullable();
            $table->enum('status', ['present', 'absent', 'late', 'half_day', 'leave'])->default('present');
            $table->decimal('overtime_hours', 5, 2)->nullable();
            // 'manual' is the primary path in this build — HR marks a day
            // directly, no external device dependency. 'biometric_import'
            // is the bonus bulk path via attendance_imports; both write into
            // this same table so reports don't need to care which produced
            // a given row.
            $table->enum('source', ['manual', 'biometric_import'])->default('manual');
            $table->foreignId('attendance_import_id')->nullable()->constrained('attendance_imports')->nullOnDelete();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
