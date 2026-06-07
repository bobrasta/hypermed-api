<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('location_id')->nullable()->after('reference_id')
                  ->constrained('locations')->nullOnDelete();
            $table->foreignId('location_from_id')->nullable()->after('location_id')
                  ->constrained('locations')->nullOnDelete();
            $table->foreignId('location_to_id')->nullable()->after('location_from_id')
                  ->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropConstrainedForeignId('location_from_id');
            $table->dropConstrainedForeignId('location_to_id');
        });
    }
};
