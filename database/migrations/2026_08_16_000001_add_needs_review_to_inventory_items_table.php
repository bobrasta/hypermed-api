<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Set true for items created via the quick-add flow (e.g. an
            // unlisted part cannibalized from a machine in the field) so
            // inventory admin can find and fill in the real SKU/cost/category.
            $table->boolean('needs_review')->default(false)->after('warranty_months');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn('needs_review');
        });
    }
};
