<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Credit sales, as Clickhuduma did them: a payment term ("30 days",
// "4 months") that sets the due date, and a delivery/transport charge
// added on top of the goods.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->unsignedSmallInteger('pay_term_number')->nullable()->after('due_date');
            $t->string('pay_term_type', 6)->nullable()->after('pay_term_number'); // days | months
            $t->bigInteger('shipping_charges')->default(0)->after('tax_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn(['pay_term_number', 'pay_term_type', 'shipping_charges']));
    }
};
