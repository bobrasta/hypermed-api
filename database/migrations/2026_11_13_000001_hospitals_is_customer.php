<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A client added from the Customers page ("New customer") is a customer
// before it has any invoice, machine or contact — this flag keeps it on
// the Customers list from the moment it is created.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->boolean('is_customer')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropColumn('is_customer');
        });
    }
};
