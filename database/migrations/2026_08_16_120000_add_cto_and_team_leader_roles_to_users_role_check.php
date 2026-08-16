<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New 'cto' and 'team_leader' roles for the approval-workflow feature.
 * Director is already represented by the existing 'admin' role (see
 * User::ADMIN_TIER / staff_screen.dart's "Director" label) — no new role
 * needed for that.
 */
return new class extends Migration
{
    private const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
        'cto', 'team_leader',
    ];

    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        $list = "'" . implode("','", self::ROLES) . "'";
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin','admin','sales_manager','sales','finance_manager','finance','technician','cs','storekeeper','hr'))");
    }
};
