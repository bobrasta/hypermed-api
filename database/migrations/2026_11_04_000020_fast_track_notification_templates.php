<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Messages for the Director's fast-track (approved past the stages).
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->setNotificationTypes([...$this->currentTypes(), 'approval_fast_tracked']);
        }

        DB::table('notification_templates')->insertOrIgnore([
            [
                'template_key' => 'approval.fast_tracked', 'notification_type' => 'approval_fast_tracked',
                'title_template' => 'Approved by {director}',
                'body_template' => '{director} approved your {item} {reference} past the remaining approval stages.',
                'description' => 'Requester — the Director approved their request past the remaining stages',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'template_key' => 'approval.fast_tracked_skipped', 'notification_type' => 'approval_fast_tracked',
                'title_template' => 'No action needed — approved by {director}',
                'body_template' => '{director} approved the {item} {reference} directly, so it no longer needs your approval.',
                'description' => 'Approvers whose stage was skipped — the Director approved past it',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('notification_templates')->whereIn('template_key', ['approval.fast_tracked', 'approval.fast_tracked_skipped'])->delete();
    }

    /** The allowed notification types as the database has them now. */
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
};
