<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Register new machine" asks for Machine name (model), Model number, Serial
// number, Manufacturer and Category (type). Model number is the only one
// without a column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('model_number', 100)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('model_number');
        });
    }
};
