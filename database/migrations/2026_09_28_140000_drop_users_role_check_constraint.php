<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Roles are managed in the Role Builder (Spatie `roles` table), not a
// hard-coded list. The users_role_check constraint pinned users.role to the
// original built-in names, so assigning a custom role (e.g. "Sales and
// Administration", regional_sales_lead, vendor_staff) failed. Validation now
// checks the roles table instead (StaffController).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
    }

    public function down(): void
    {
        // Not re-added: users may now hold custom roles the old list rejects.
    }
};
