<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Permission;

// In-app messaging chat review. Created here, not only in PermissionSeeder,
// because the seeder's already-seeded guard skips new catalog entries in
// production. Granted to NO role: it is reserved
// (EffectivePermissionResolver::RESERVED), so only the Director can hand it
// out, and it does nothing until the Director switches chat review on.
return new class extends Migration
{
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'messaging.read_others', 'guard_name' => 'web'], [
            'module' => 'admin',
            'label' => 'Review Staff Chats',
            'description' => "Read other people's in-app chats while the Director has chat review switched on; every read is logged. Reserved: only the Director can grant it",
        ]);
        $this->bust();
    }

    public function down(): void
    {
        Permission::where('name', 'messaging.read_others')->delete();
        $this->bust();
    }

    private function bust(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $id) {
            Cache::forget("user:{$id}:permissions");
        }
    }
};
