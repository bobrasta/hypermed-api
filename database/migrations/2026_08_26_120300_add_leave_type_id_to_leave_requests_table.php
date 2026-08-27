<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\LeaveType;

/**
 * Adds the FK to the new leave_types catalog. Keeps the old `type` string
 * column (no drop) so any code/report still reading it doesn't break —
 * LeaveController now writes both, backfills existing rows from the old
 * enum values, and Flutter is being moved onto leave_type_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('leave_type_id')->nullable()->after('type')
                ->constrained('leave_types')->nullOnDelete();
        });

        $map = [
            'sick'     => 'sick',
            'vacation' => 'annual',
            'other'    => 'compassionate',
        ];
        foreach ($map as $oldType => $newKey) {
            $leaveType = LeaveType::where('key', $newKey)->first();
            if ($leaveType) {
                DB::table('leave_requests')->where('type', $oldType)->update(['leave_type_id' => $leaveType->id]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_type_id');
        });
    }
};
