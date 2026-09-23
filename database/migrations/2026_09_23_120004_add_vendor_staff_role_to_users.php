<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Section 16.6 assumption #1: vendor staff (clearing company, USIRI) get
// individual named logins to upload their own documents — never a shared
// login. Reuses the existing users table + role system rather than a
// parallel auth mechanism: vendor_id scopes a vendor_staff user to exactly
// their own vendor's fees/jobs (enforced in the controllers, not via the
// screens.* permission catalog — a vendor login sees nothing of the
// internal app, so it doesn't participate in that system at all).
return new class extends Migration
{
    private const ROLES = [
        'super_admin', 'admin', 'sales_manager', 'sales',
        'finance_manager', 'finance', 'technician', 'cs', 'storekeeper', 'hr',
        'cto', 'team_leader', 'procurement_manager', 'accountant', 'logistics',
        'vendor_staff',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        $list = "'" . implode("','", self::ROLES) . "'";
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ($list))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
        $prior = array_values(array_diff(self::ROLES, ['vendor_staff']));
        $list = "'" . implode("','", $prior) . "'";
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ($list))");

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });
    }
};
