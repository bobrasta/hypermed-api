<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hospital_name_raw')->nullable();
            $table->foreignId('contact_id')->nullable();
            $table->string('contact_name_raw')->nullable();
            $table->string('machine_type')->nullable();
            $table->bigInteger('deal_value')->default(0);
            $table->enum('stage', ['lead', 'qualified', 'demo_scheduled', 'proposal_sent', 'negotiation', 'won', 'lost'])->default('lead');
            $table->date('demo_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
