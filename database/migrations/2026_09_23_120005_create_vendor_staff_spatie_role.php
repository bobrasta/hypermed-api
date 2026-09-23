<?php

use Spatie\Permission\Models\Role;
use Illuminate\Database\Migrations\Migration;

// Same gap this codebase has hit three times before (procurement_manager/
// accountant/logistics, then staff.manage, then hospitals.manage) —
// widening users_role_check alone does NOT create the matching Spatie Role
// row, and syncRoles() throws RoleDoesNotExist without it. vendor_staff
// deliberately gets no screens.*/permission grants at all — it's a narrow
// document-upload portal gated by vendor_id in the controllers, not a
// participant in the internal app's screen/permission catalog.
return new class extends Migration
{
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'vendor_staff', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        // Intentionally a no-op — same reasoning as the equivalent prior
        // role-repair migrations, rolling back would reopen the bug.
    }
};
