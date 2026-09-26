<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Calls, visits, demos and meetings a rep logs or plans. contact_interactions
// can't carry this: it has no user column (so no rep attribution) and needs a
// contact record. occurs_at in the future = planned ("up next"), past = done.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20); // call | visit | meeting | demo | email | whatsapp
            $table->foreignId('sales_lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hospital_name_raw')->nullable();
            $table->string('subject');
            $table->text('note')->nullable();
            $table->timestamp('occurs_at');
            $table->timestamps();
            $table->index(['created_by', 'occurs_at']);
            $table->index('occurs_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_activities');
    }
};
