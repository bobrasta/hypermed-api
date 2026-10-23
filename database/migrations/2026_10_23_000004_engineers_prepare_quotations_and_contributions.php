<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * 2026-10-23 (user): engineers prepare quotations — not orders or invoices —
 * and stay credited for them; the Director gauges bonuses from a per-person
 * record of who did what (quotations, those that became invoices,
 * installations, services, deals won).
 *
 * - sales.prepare_quotation: create/edit/delete one's own draft quotations.
 * - screens.quotations: a Quotations menu entry on its own, for roles that
 *   don't have the whole Sales menu.
 * - screens.contributions: the Staff contributions screen (Director, CTO).
 * - quotation_prepared notification, sent to whoever issues quotations.
 *
 * Same layers as 2026_10_22_110000: seeder source lists, this direct grant
 * (the seeder is a no-op on a seeded DB), and cache busts.
 */
return new class extends Migration
{
    private const GRANTS = [
        'sales.prepare_quotation' => ['sales', 'Prepare Quotations', 'Create and edit their own draft quotations — no sales orders or invoices', ['technician', 'team_leader']],
        'screens.quotations' => ['screens', 'View Quotations Screen', null, ['technician', 'team_leader']],
        'screens.contributions' => ['screens', 'View Staff Contributions Screen', null, ['super_admin', 'cto']],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => [$module, $label, $description, $roles]) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'],
                array_filter(['module' => $module, 'label' => $label, 'description' => $description]));
            foreach (Role::whereIn('name', $roles)->get() as $role) {
                if (! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes([...$this->currentTypes(), 'quotation_prepared']);
        }
        DB::table('notification_templates')->insertOrIgnore([
            'template_key' => 'quotation.prepared', 'notification_type' => 'quotation_prepared',
            'title_template' => 'Quotation {quotation_number} prepared by {preparer}',
            'body_template' => '{preparer} prepared quotation {quotation_number} for {client_name} ({total}). It is a draft until someone in Sales sends it to the client.',
            'description' => 'An engineer (or anyone without authority to issue quotations) saved a quotation; sent to the people who issue them.',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->forget();
    }

    public function down(): void
    {
        DB::table('notification_templates')->where('template_key', 'quotation.prepared')->delete();
        DB::table('notifications')->where('type', 'quotation_prepared')->delete();
        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes(array_values(array_diff($this->currentTypes(), ['quotation_prepared'])));
        }
        Permission::whereIn('name', array_keys(self::GRANTS))->delete();
        $this->forget();
    }

    /** The allowed notification types as the database has them now, so no earlier list is copied (and gone stale). */
    private function currentTypes(): array
    {
        $def = (string) DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = 'notifications_type_check'")?->def;
        preg_match_all("/'([a-z_]+)'/", $def, $m);

        return array_values(array_unique($m[1]));
    }

    private function setNotificationTypes(array $types): void
    {
        DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ('" . implode("','", array_unique($types)) . "'))");
    }

    private function forget(): void
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (User::pluck('id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
