<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tenders: a 'not_submitted' outcome, and the reason a tender ended as not
// submitted / cancelled / lost. Tender type 'pre_qualification' needs no
// schema change (tender_type is a plain string).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tenders', 'status_reason')) {
            Schema::table('tenders', fn (Blueprint $t) => $t->text('status_reason')->nullable()->after('status'));
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenders DROP CONSTRAINT IF EXISTS tenders_status_check');
            DB::statement("ALTER TABLE tenders ADD CONSTRAINT tenders_status_check CHECK (status IN ('identified','preparing_bid','bid_submitted','awaiting_award','won','lost','cancelled','not_submitted','accepted','performance_security_submitted','contract_signed','fulfilling','delivered','closed'))");
        }
    }

    public function down(): void
    {
    }
};
