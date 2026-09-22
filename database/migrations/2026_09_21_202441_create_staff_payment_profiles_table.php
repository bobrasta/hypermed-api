<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 15.2/15.7: where a staff member's payment details live so
 * per-diem submissions can snapshot them (payment_snapshot on
 * per_diem_requests) and the export/viewer can show a masked account
 * number to everyone except the owner/accountant/finance/admin tiers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_payment_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('account_number');
            $table->string('account_name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_payment_profiles');
    }
};
