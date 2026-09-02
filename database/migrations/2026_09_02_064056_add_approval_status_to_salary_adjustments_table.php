<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

// Salary adjustments used to apply to the contract the instant the
// accountant recorded them ("recording it is the approval"). That's the
// self-approval gap this whole policy pass exists to close — this adds a
// pending/approved state so a Director has to approve before base_salary
// actually changes. Existing rows were created under the old convention
// (already applied, approved_by already = created_by) — backfilled to
// 'approved' so history doesn't retroactively look unapproved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->string('status')->default('pending')->after('reason');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });

        DB::table('salary_adjustments')->update([
            'status' => 'approved',
            'approved_at' => DB::raw('updated_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('salary_adjustments', function (Blueprint $table) {
            $table->dropColumn(['status', 'approved_at']);
        });
    }
};
