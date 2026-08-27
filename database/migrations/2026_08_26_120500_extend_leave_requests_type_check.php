<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `type` (legacy string) keeps getting written alongside the new
 * `leave_type_id` FK — LeaveController now sets `type` to the leave_type's
 * own `key` so the two never disagree, rather than lossily mapping onto
 * the old 3-value enum. Extends the check constraint to match.
 */
return new class extends Migration
{
    private const TYPES = ['sick', 'vacation', 'other', 'annual', 'maternity', 'compassionate', 'public_holiday'];

    public function up(): void
    {
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_type_check');
        $list = "'" . implode("','", self::TYPES) . "'";
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE leave_requests DROP CONSTRAINT leave_requests_type_check');
        DB::statement("ALTER TABLE leave_requests ADD CONSTRAINT leave_requests_type_check CHECK (type IN ('sick','vacation','other'))");
    }
};
