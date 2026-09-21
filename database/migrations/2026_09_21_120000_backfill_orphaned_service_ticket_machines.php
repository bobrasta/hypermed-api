<?php

use App\Models\ServiceTicket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Found on production right after 2026_09_21_110000's deploy: 24 tickets
 * (created via ServiceTicketSeeder, run manually against production for
 * demo data — chained from DatabaseSeeder, not part of the deploy's
 * auto-run seeder list, and unaware the pivot table exists) with zero
 * service_ticket_machines rows. That migration's backfill only covered
 * what existed in the table at the moment IT ran; anything inserted
 * directly (a seeder, a factory, code not yet written) after that but
 * before ServiceTicket::booted()'s new safety net (added alongside this
 * migration) would still have the same gap. This closes it for whatever
 * exists right now; the model event stops it from recurring.
 */
return new class extends Migration
{
    public function up(): void
    {
        ServiceTicket::whereDoesntHave('machinePivots')
            ->select('id', 'machine_id', 'status', 'resolved_at')
            ->chunkById(500, function ($tickets) {
                $now = now();
                $rows = $tickets->map(fn ($t) => [
                    'service_ticket_id' => $t->id,
                    'machine_id'        => $t->machine_id,
                    'status'            => $t->status === 'resolved' ? 'done' : 'pending',
                    'completed_at'      => $t->status === 'resolved' ? $t->resolved_at : null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ])->all();
                DB::table('service_ticket_machines')->insert($rows);
            });
    }

    public function down(): void
    {
        // Not reversible in a meaningful way — these rows are
        // indistinguishable from ones the original backfill created.
    }
};
