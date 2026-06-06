<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_code', 20)->nullable();
            $table->enum('type', ['public', 'private', 'mission', 'clinic'])->default('public');
            $table->string('region')->nullable();
            $table->string('district')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->enum('zone', ['coastal', 'northern', 'lake', 'central', 'shighland', 'southern'])->nullable();
            $table->unsignedInteger('machine_count')->default(0);
            $table->unsignedInteger('machines_operational')->default(0);
            $table->bigInteger('revenue_monthly')->default(0);
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospitals');
    }
};
