<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type')->unique(); // e.g. 'invoice', 'purchase_order'
            $table->string('label'); // e.g. 'Invoice'
            $table->string('prefix', 20);
            $table->unsignedTinyInteger('digits')->default(4);
            $table->boolean('reset_yearly')->default(true);
            $table->unsignedInteger('next_number')->default(1);
            $table->unsignedSmallInteger('year')->nullable(); // the year next_number currently applies to
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
