<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('serial_no')->unique();
            $table->string('model');
            $table->string('type');
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('ward')->nullable();
            $table->date('install_date')->nullable();
            $table->date('warranty_expiry')->nullable();
            $table->enum('status', ['operational', 'needs_service', 'down', 'warranty', 'idle'])->default('operational');
            $table->bigInteger('revenue_per_month')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};
