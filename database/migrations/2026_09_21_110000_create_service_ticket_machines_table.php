<?php

use App\Models\ServiceTicket;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6 of hypermed_claude_code_prompt.md, generalized per direct user
 * instruction (2026-09-21) to cover every ticket type, not just
 * Installation: a ticket can reference more than one machine.
 *
 * service_tickets.machine_id is kept (not dropped, not made nullable) as a
 * backward-compatible "primary machine" pointer — always kept in sync with
 * the lowest-id active row here by ServiceTicketController — so an old app
 * build that only ever reads machine_id keeps working unchanged.
 *
 * status is deliberately generic ('pending'/'done'), not
 * installation-specific: for an Installation ticket, 'done' means the full
 * Section 13 handover (lifecycle -> Installed, ownership transfer, warranty
 * starts); for every other ticket type, 'done' just means that unit's work
 * on this ticket is finished — no lifecycle change. See
 * ServiceTicketController::completeMachine().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_ticket_machines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_ticket_id')->constrained('service_tickets')->cascadeOnDelete();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            // Soft-removed (not deleted) so a mid-ticket "delivery delayed"
            // removal stays in the audit trail — see Section 6: "removed
            // from an open ticket ... with a required reason".
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('removal_reason')->nullable();
            $table->timestamps();

            $table->unique(['service_ticket_id', 'machine_id']);
        });

        // Backfill: every existing ticket becomes a one-line list, matching
        // its current machine_id/status exactly — no behavior change for
        // data that predates this table.
        ServiceTicket::query()->select('id', 'machine_id', 'status', 'resolved_at')
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
                \Illuminate\Support\Facades\DB::table('service_ticket_machines')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_machines');
    }
};
