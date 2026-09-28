<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// TestAccessControlSeeder's demo logins (test.*@hypermed.tz, all sharing the
// default password) must not stay usable once real staff are on the VPS.
// Deactivate rather than delete: they may own tickets, leave requests or
// audit rows. Passwords are scrambled and tokens revoked so an old session
// or the well-known default can't get back in.
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('users')->where('email', 'like', 'test.%@hypermed.tz')->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        foreach ($ids as $id) {
            DB::table('users')->where('id', $id)->update([
                'is_active' => false,
                'password'  => Hash::make(Str::random(48)),
            ]);
        }

        DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->whereIn('tokenable_id', $ids)
            ->delete();
    }

    public function down(): void
    {
        // Irreversible by design — re-enable individual accounts by hand if needed.
    }
};
