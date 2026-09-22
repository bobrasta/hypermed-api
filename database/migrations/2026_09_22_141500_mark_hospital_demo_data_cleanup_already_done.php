<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Retroactively marks RemoveDemoDataSeeder's cleanup as already complete,
 * without ever running its (stale-whitelist) deletion query again.
 *
 * Companion to the RemoveDemoDataSeeder fix landing in the same deploy
 * (see that file's docblock): its REAL_CODES whitelist was missing 27
 * genuinely real hospitals, so the very next time that seeder ran -- with
 * or without the DONE_FLAG guard it now also has -- it would have deleted
 * all 27 of them, plus every row ImportNationalFacilityRegistrySeeder is
 * about to insert in this same deploy. There is nothing left to clean up:
 * production currently holds exactly the real hospital set (confirmed via
 * the API right before writing this), so the correct one-time action is
 * to mark the job done, not to run it once more against data that no
 * longer needs cleaning.
 *
 * Runs before any db:seed step in railway.toml's startCommand (migrations
 * always run first), so RemoveDemoDataSeeder's guard is already satisfied
 * by the time it would otherwise execute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::set('hospitals_demo_data_removed', '1');
    }

    public function down(): void
    {
        // Intentionally a no-op -- reverting this would just reopen the
        // data-loss bug it exists to close, for no benefit.
    }
};
