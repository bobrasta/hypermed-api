<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create inventory_items
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('category', ['machine_part', 'consumable', 'accessory', 'equipment', 'other'])->default('machine_part');
            $table->enum('unit_of_measure', ['piece', 'box', 'litre', 'set', 'kg', 'roll'])->default('piece');
            $table->bigInteger('unit_cost')->default(0);
            $table->string('currency', 10)->default('TZS');
            $table->unsignedInteger('stock_qty')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->string('supplier')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Migrate spare_parts rows (preserve IDs so parts_used FKs still match)
        DB::statement("
            INSERT INTO inventory_items
                (id, sku, name, description, category, unit_of_measure, unit_cost, currency,
                 stock_qty, reorder_level, supplier, is_active, created_at, updated_at)
            SELECT id, part_number, name, description, 'machine_part', 'piece', unit_cost, currency,
                   stock_qty, reorder_level, supplier, true, created_at, updated_at
            FROM spare_parts
        ");

        // Reset PostgreSQL sequence so next INSERT gets the right ID
        DB::statement("SELECT setval('inventory_items_id_seq', COALESCE((SELECT MAX(id) FROM inventory_items), 1))");

        // 3. Create inventory_item_machine_models (replaces spare_part_machine_models)
        Schema::create('inventory_item_machine_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('machine_model');
            $table->timestamps();
        });

        DB::statement("
            INSERT INTO inventory_item_machine_models
                (id, inventory_item_id, machine_model, created_at, updated_at)
            SELECT id, spare_part_id, machine_model, created_at, updated_at
            FROM spare_part_machine_models
        ");

        DB::statement("SELECT setval('inventory_item_machine_models_id_seq', COALESCE((SELECT MAX(id) FROM inventory_item_machine_models), 1))");

        // 4. Swap parts_used.spare_part_id → inventory_item_id
        //    Add nullable first, fill data, then make non-nullable
        Schema::table('parts_used', function (Blueprint $table) {
            $table->unsignedBigInteger('inventory_item_id')->nullable()->after('ticket_id');
            $table->foreign('inventory_item_id')->references('id')->on('inventory_items')->nullOnDelete();
        });

        DB::statement('UPDATE parts_used SET inventory_item_id = spare_part_id');

        DB::statement('ALTER TABLE parts_used ALTER COLUMN inventory_item_id SET NOT NULL');

        Schema::table('parts_used', function (Blueprint $table) {
            $table->dropForeign(['spare_part_id']);
            $table->dropColumn('spare_part_id');
        });

        // 5. Drop old tables
        Schema::dropIfExists('spare_part_machine_models');
        Schema::dropIfExists('spare_parts');
    }

    public function down(): void
    {
        Schema::create('spare_parts', function (Blueprint $table) {
            $table->id();
            $table->string('part_number')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('unit_cost')->default(0);
            $table->string('currency', 10)->default('TZS');
            $table->unsignedInteger('stock_qty')->default(0);
            $table->unsignedInteger('reorder_level')->default(0);
            $table->string('supplier')->nullable();
            $table->timestamps();
        });

        Schema::create('spare_part_machine_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spare_part_id')->constrained()->cascadeOnDelete();
            $table->string('machine_model');
            $table->timestamps();
        });

        Schema::table('parts_used', function (Blueprint $table) {
            $table->unsignedBigInteger('spare_part_id')->nullable()->after('ticket_id');
            $table->foreign('spare_part_id')->references('id')->on('spare_parts')->cascadeOnDelete();
        });

        DB::statement('UPDATE parts_used SET spare_part_id = inventory_item_id');

        Schema::table('parts_used', function (Blueprint $table) {
            $table->dropForeign(['inventory_item_id']);
            $table->dropColumn('inventory_item_id');
        });

        Schema::dropIfExists('inventory_item_machine_models');
        Schema::dropIfExists('inventory_items');
    }
};
