<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_code', 20)->nullable()->unique();
            $table->enum('type', ['manufacturer', 'distributor', 'importer', 'local_vendor'])->default('distributor');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('website')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->default('Tanzania');
            $table->string('currency', 10)->default('USD');
            $table->enum('payment_terms', ['prepaid', 'net_15', 'net_30', 'net_60', 'net_90'])->default('net_30');
            $table->unsignedInteger('lead_time_days')->default(14);
            $table->unsignedTinyInteger('rating')->nullable()->comment('1-5 stars');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
