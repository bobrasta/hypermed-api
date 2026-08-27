<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('role')
                ->constrained('positions')->nullOnDelete();
            $table->enum('gender', ['male', 'female'])->nullable()->after('position_id');
            $table->date('hire_date')->nullable()->after('gender');
            $table->string('next_of_kin_name')->nullable()->after('hire_date');
            $table->string('next_of_kin_phone')->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_relationship')->nullable()->after('next_of_kin_phone');
            $table->string('nssf_number')->nullable()->after('next_of_kin_relationship');
            $table->string('tin_number')->nullable()->after('nssf_number');
            $table->string('nida_number')->nullable()->after('tin_number');
            $table->string('biometric_id')->nullable()->unique()->after('nida_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
            $table->dropColumn([
                'gender', 'hire_date', 'next_of_kin_name', 'next_of_kin_phone',
                'next_of_kin_relationship', 'nssf_number', 'tin_number',
                'nida_number', 'biometric_id',
            ]);
        });
    }
};
