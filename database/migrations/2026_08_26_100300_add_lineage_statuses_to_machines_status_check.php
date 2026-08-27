<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const STATUSES = [
        'pending_installation', 'pending_signoff',
        'operational', 'needs_service', 'down', 'warranty', 'idle',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE machines DROP CONSTRAINT machines_status_check');
        $list = "'" . implode("','", self::STATUSES) . "'";
        DB::statement("ALTER TABLE machines ADD CONSTRAINT machines_status_check CHECK (status IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE machines DROP CONSTRAINT machines_status_check');
        DB::statement("ALTER TABLE machines ADD CONSTRAINT machines_status_check CHECK (status IN ('operational','needs_service','down','warranty','idle'))");
    }
};
