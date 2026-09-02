<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// vendor_bills.status has a real Postgres CHECK constraint (unlike most
// other status columns in this codebase, which are plain strings with no
// DB-level enum) — the new Director-approval step needs 'approved' added
// as a valid value between 'pending' and 'partial'/'paid'.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE vendor_bills DROP CONSTRAINT vendor_bills_status_check');
        DB::statement("ALTER TABLE vendor_bills ADD CONSTRAINT vendor_bills_status_check CHECK (status IN ('pending', 'approved', 'partial', 'paid', 'overdue', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE vendor_bills DROP CONSTRAINT vendor_bills_status_check');
        DB::statement("ALTER TABLE vendor_bills ADD CONSTRAINT vendor_bills_status_check CHECK (status IN ('pending', 'partial', 'paid', 'overdue', 'cancelled'))");
    }
};
