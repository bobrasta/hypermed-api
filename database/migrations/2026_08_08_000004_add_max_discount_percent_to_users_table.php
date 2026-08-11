<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Null = no cap (admins). A sales rep discounting beyond this on a
            // quotation/sales order forces that document into approval_status=pending.
            $table->decimal('max_discount_percent', 5, 2)->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('max_discount_percent');
        });
    }
};
