<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New 'procurement_manager', 'accountant', and 'logistics' roles — closes the
 * gaps found in the 2026-08-25 role-flow planning pass: procurement
 * (requisition/PO/payment) and physical delivery/receiving had no dedicated
 * owner at all. See project memory project_hypermed_role_flow_planning.md.
 */
return new class extends Migration
{
    private const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
        'cto', 'team_leader', 'procurement_manager', 'accountant', 'logistics',
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
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin','admin','sales_manager','sales','finance_manager','finance','technician','cs','storekeeper','hr','cto','team_leader'))");
    }
};
