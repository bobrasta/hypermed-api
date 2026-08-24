<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('per_diem_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('per_diem_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq_no');
            $table->date('date');
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->string('site_name')->nullable();
            $table->string('activity')->nullable();
            $table->unsignedBigInteger('labor_cost')->default(0);
            $table->unsignedBigInteger('per_diem_cost')->default(0);
            $table->unsignedBigInteger('transport_fare')->default(0);
            $table->timestamps();
            $table->index('per_diem_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('per_diem_lines');
    }
};
