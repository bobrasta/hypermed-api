<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * New dedicated 'hr' role — leave requests and late-arrival reports need a
 * real owner, not a stand-in via admin. Mirrors sales_manager/finance_manager's
 * precedent of a department getting its own role rather than overloading admin.
 */
return new class extends Migration
{
    private const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
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
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('super_admin','admin','sales_manager','sales','finance_manager','finance','technician','cs','storekeeper'))");
    }
};
