<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extend the existing inventory_items table with rich product fields
        Schema::table('inventory_items', function (Blueprint $table) {
            // Replace old category enum with broader set
            $table->dropColumn('category');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->enum('category', [
                'biomedical_equipment',
                'spare_part',
                'consumable',
                'hospital_furniture',
                'ppe',
                'accessory',
                'other',
            ])->default('spare_part')->after('name');

            // Product identity
            $table->string('manufacturer')->nullable()->after('category');
            $table->string('model_number')->nullable()->after('manufacturer');
            $table->string('country_of_origin')->nullable()->after('model_number');
            $table->string('barcode')->nullable()->unique()->after('sku');
            $table->enum('barcode_type', ['code128', 'ean13', 'qr'])->default('code128')->after('barcode');

            // Regulatory
            $table->boolean('has_ce')->default(false)->after('country_of_origin');
            $table->boolean('has_fda')->default(false)->after('has_ce');
            $table->boolean('has_tbs')->default(false)->after('has_fda');

            // Physical specs
            $table->string('weight_kg')->nullable()->after('has_tbs');
            $table->string('dimensions')->nullable()->after('weight_kg'); // e.g. "120 x 60 x 80 cm"
            $table->string('voltage')->nullable()->after('dimensions');   // e.g. "220V / 50Hz"
            $table->json('specifications')->nullable()->after('voltage'); // flexible key-value specs

            // Stock & lifecycle
            $table->unsignedInteger('shelf_life_days')->nullable()->after('specifications');
            $table->foreignId('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete()->after('supplier');

            // Multi-currency cost
            $table->string('cost_currency', 10)->default('TZS')->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropForeign(['preferred_supplier_id']);
            $table->dropColumn([
                'manufacturer', 'model_number', 'country_of_origin',
                'barcode', 'barcode_type',
                'has_ce', 'has_fda', 'has_tbs',
                'weight_kg', 'dimensions', 'voltage', 'specifications',
                'shelf_life_days', 'preferred_supplier_id', 'cost_currency',
            ]);
            $table->dropColumn('category');
        });

        Schema::table('inventory_items', function (Blueprint $table) {
            $table->enum('category', ['machine_part', 'consumable', 'accessory', 'equipment', 'other'])
                  ->default('machine_part');
        });
    }
};
