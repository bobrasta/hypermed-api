<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('category');
            $table->foreignId('category_id')->nullable()->after('name')
                ->constrained('categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');

            $table->enum('category', [
                'biomedical_equipment', 'spare_part', 'consumable',
                'hospital_furniture', 'ppe', 'accessory', 'other',
            ])->default('spare_part')->after('name');
        });
    }
};
