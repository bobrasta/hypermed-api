<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'staff_group')) {
                $table->enum('staff_group', ['field', 'office', 'admin'])->nullable()->after('role');
            }
            if (! Schema::hasColumn('users', 'zone')) {
                $table->string('zone')->nullable()->after('staff_group');
            }
            if (! Schema::hasColumn('users', 'workload')) {
                $table->decimal('workload', 5, 2)->default(0.00)->after('avail_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['staff_group', 'zone', 'workload']);
        });
    }
};
