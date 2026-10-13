<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// The Director's decision (2026-10-13): the current CTO gets everything the
// Director has except final authorisation of money — a per-user
// authority.admin_tier override on this one person (a future CTO does not
// inherit it). EffectivePermissionResolver::RESERVED keeps money approval,
// payments and the Role Builder out of it. The Director can remove it in
// Settings > Team & Roles > member permissions.
return new class extends Migration
{
    private const CTO_EMAIL = 'sharifrajabu@hypermed.co.tz';

    public function up(): void
    {
        $user = User::where('email', self::CTO_EMAIL)->where('role', 'cto')->first();
        $permissionId = DB::table('permissions')->where('name', 'authority.admin_tier')->value('id');
        if (! $user || ! $permissionId) {
            return;
        }

        DB::table('user_permission_overrides')->updateOrInsert(
            ['user_id' => $user->id, 'permission_id' => $permissionId],
            [
                'effect' => 'allow',
                'scope' => null,
                'reason' => 'CTO: full admin except final authorisation of money (Director, 2026-10-13)',
                'created_by' => User::where('role', 'admin')->where('is_active', true)->value('id'),
                'created_at' => now(),
            ]
        );
        Cache::forget("user:{$user->id}:permissions");
    }

    public function down(): void
    {
        $user = User::where('email', self::CTO_EMAIL)->first();
        $permissionId = DB::table('permissions')->where('name', 'authority.admin_tier')->value('id');
        if ($user && $permissionId) {
            DB::table('user_permission_overrides')->where('user_id', $user->id)->where('permission_id', $permissionId)->delete();
            Cache::forget("user:{$user->id}:permissions");
        }
    }
};
