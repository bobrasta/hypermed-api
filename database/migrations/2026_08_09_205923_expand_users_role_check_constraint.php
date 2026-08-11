<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extends the role model from the flat 5-role set to 9 roles, adding a
 * manager tier per department (sales_manager, finance_manager), a top
 * system-maintainer tier above the business-owner 'admin' (super_admin),
 * and a warehouse role (storekeeper) needed for the sales-order pick-list
 * workflow. Existing role values are kept unchanged for backward
 * compatibility with every row and every role check already in the app.
 */
return new class extends Migration
{
    private const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper',
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
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin','technician','sales','finance','cs'))");
    }
};
