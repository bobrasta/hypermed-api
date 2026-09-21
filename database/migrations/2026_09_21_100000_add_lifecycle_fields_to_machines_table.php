<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section 13 of hypermed_claude_code_prompt.md: machines must be receivable
 * into a store before they belong to any hospital ("In Stock"), which
 * hospital_id's original NOT NULL constraint made unrepresentable — flagged
 * during the initial audit of this spec. Also adds purchase_cost (Section
 * 12's viability calculation needs it) and the small set of fields the new
 * "Receive Machine" form captures.
 *
 * lifecycle_stage is additive, not a replacement for the existing
 * pending_installation/pending_signoff `status` values — those already
 * describe the Sales-Order-delivery path precisely (see
 * MachineRegistrationService, MachineController::signOff()). A machine
 * delivered via a sale already has hospital_id set at delivery time (that
 * IS "Allocated" in this spec's vocabulary, just under existing naming);
 * lifecycle_stage layers the broader Receive/Allocate/Handover model on
 * top without touching that working flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // hospital_id nullable: an In Stock machine (arrived via Receive
        // Machine, not yet tied to any sale) has no hospital at all yet.
        Schema::table('machines', function (Blueprint $table) {
            $table->dropForeign(['hospital_id']);
        });
        DB::statement('ALTER TABLE machines ALTER COLUMN hospital_id DROP NOT NULL');
        Schema::table('machines', function (Blueprint $table) {
            $table->foreign('hospital_id')->references('id')->on('hospitals')->cascadeOnDelete();
        });

        Schema::table('machines', function (Blueprint $table) {
            // 'installed' default backfills every existing row (Postgres
            // applies a column default retroactively on ADD COLUMN) — the
            // pending_installation/pending_signoff subset is corrected to
            // 'allocated' below, since those haven't physically shipped yet.
            $table->string('lifecycle_stage')->default('installed')->after('status');
            $table->string('manufacturer')->nullable()->after('type');
            $table->string('condition')->nullable()->after('manufacturer');
            $table->date('arrival_date')->nullable()->after('install_date');
            // Where an In Stock machine physically sits — reuses the same
            // Location model inventory already uses for warehouses, rather
            // than a new free-text field.
            $table->foreignId('store_location_id')->nullable()->after('hospital_id')
                ->constrained('locations')->nullOnDelete();
            // What Hypermed paid to acquire it — not the current replacement
            // price. Stored in its original currency; purchase_cost_tsh is
            // the TSh-converted amount at a recorded rate, so Section 12's
            // percentage-of-purchase-cost comparison doesn't drift as FX
            // rates change later.
            $table->bigInteger('purchase_cost')->nullable()->after('revenue_per_month');
            $table->string('purchase_cost_currency', 8)->nullable()->after('purchase_cost');
            $table->decimal('purchase_cost_fx_rate', 12, 4)->nullable()->after('purchase_cost_currency');
            $table->bigInteger('purchase_cost_tsh')->nullable()->after('purchase_cost_fx_rate');
            $table->timestamp('purchase_cost_recorded_at')->nullable()->after('purchase_cost_tsh');
        });

        DB::table('machines')
            ->whereIn('status', ['pending_installation', 'pending_signoff'])
            ->update(['lifecycle_stage' => 'allocated']);
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_stage', 'manufacturer', 'condition', 'arrival_date',
                'purchase_cost', 'purchase_cost_currency', 'purchase_cost_fx_rate',
                'purchase_cost_tsh', 'purchase_cost_recorded_at',
            ]);
            $table->dropConstrainedForeignId('store_location_id');
            $table->dropForeign(['hospital_id']);
        });
        DB::statement('ALTER TABLE machines ALTER COLUMN hospital_id SET NOT NULL');
        Schema::table('machines', function (Blueprint $table) {
            $table->foreign('hospital_id')->references('id')->on('hospitals')->cascadeOnDelete();
        });
    }
};
