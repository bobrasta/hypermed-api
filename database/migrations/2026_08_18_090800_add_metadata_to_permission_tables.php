<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extends Spatie's own permissions/roles/role_has_permissions tables rather
// than hand-rolling parallel ones — module/label/description let the Role
// Builder group and display the catalog; is_system protects the 12 legacy
// roles from deletion; scope lets a role's grant of a permission carry a
// 'masked'/'own'/'team'/'all' qualifier (interpreted by consuming code later).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('module')->nullable()->after('name');
            $table->string('label')->nullable()->after('module');
            $table->text('description')->nullable()->after('label');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('name');
        });

        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->string('scope')->default('all')->after('permission_id');
        });
    }

    public function down(): void
    {
        Schema::table('role_has_permissions', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->dropColumn(['module', 'label', 'description']);
        });
    }
};
