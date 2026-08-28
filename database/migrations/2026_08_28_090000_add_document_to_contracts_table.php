<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('document_path')->nullable()->after('renewed_from_contract_id');
            $table->string('document_name')->nullable()->after('document_path');
            $table->timestamp('document_uploaded_at')->nullable()->after('document_name');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['document_path', 'document_name', 'document_uploaded_at']);
        });
    }
};
