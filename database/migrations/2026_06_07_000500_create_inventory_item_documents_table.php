<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_item_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');                      // display label, e.g. "Service Manual"
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type')->default('application/pdf');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->enum('document_type', [
                'datasheet', 'user_manual', 'service_manual',
                'certificate', 'import_permit', 'warranty_card', 'other',
            ])->default('datasheet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_item_documents');
    }
};
