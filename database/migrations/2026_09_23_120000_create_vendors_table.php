<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 16: transit/delivery vendors (clearing company, USIRI, future
// logistics vendors). Distinct from VendorBill's free-text 'vendor' name
// field — that's an ad-hoc accounts-payable bill from any supplier, this is
// a structured registered entity with a payment account and its own staff
// logins for document upload.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // clearing|delivery|other
            $table->string('tin')->nullable();
            $table->string('payment_account_type')->nullable(); // bank|mobile_money
            $table->string('payment_account_name')->nullable();
            $table->string('payment_account_number')->nullable();
            $table->string('payment_bank_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('type');
        });

        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_type_check CHECK (type IN ('clearing','delivery','other'))");
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_payment_account_type_check CHECK (payment_account_type IS NULL OR payment_account_type IN ('bank','mobile_money'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};
