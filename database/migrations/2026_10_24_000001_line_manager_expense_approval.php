<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-10-24 (user): everyone's expenses go to their own manager first —
 * Florian's to Gabriel, Sharif's people to Sharif, each department head's
 * people to them — then straight to Finance; Director-only categories and
 * the safety-net threshold still go on to the Director. approver_id is the
 * manager (users.manager_id) the expense waits on at status pending_manager.
 * Nobody with a manager: the old CTO stage, unchanged.
 *
 * The sales manager (Gabriel) gets the Approvals screen — every other
 * manager role already had it, and it's where his team's expenses (and the
 * PO sales stage he already holds) are approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('approver_id')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
        DB::table('notification_templates')->insertOrIgnore([
            'template_key' => 'expense.submitted_manager', 'notification_type' => 'expense_requested',
            'title_template' => 'Expense from {name} awaits your approval',
            'body_template' => '{name} submitted {expense_name} (TSh {gross_amount}). As their manager, approve or reject it.',
            'description' => 'An expense submitted by someone who reports to you (their line manager approves first).',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $permission = Permission::firstOrCreate(['name' => 'screens.approvals', 'guard_name' => 'web'],
            ['module' => 'screens', 'label' => 'View Approvals Screen']);
        $role = Role::where('name', 'sales_manager')->first();
        if ($role && ! $role->hasPermissionTo($permission)) {
            $role->givePermissionTo($permission);
        }
        $this->forget();
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('template_key', 'expense.submitted_manager')->delete();
        Schema::table('expenses', fn (Blueprint $t) => $t->dropConstrainedForeignId('approver_id'));
        $permission = Permission::where('name', 'screens.approvals')->first();
        $role = Role::where('name', 'sales_manager')->first();
        if ($permission && $role && $role->hasPermissionTo($permission)) {
            $role->revokePermissionTo($permission);
        }
        $this->forget();
    }
};
