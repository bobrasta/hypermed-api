<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the shareable, per-department flow-diagram research tool: the
// current live state of each department's diagram (flow_departments,
// what /flows/{department} loads and edits — last-save-wins), plus a full
// audit trail of every save (flow_suggestions) so contributions can be
// reviewed even after the live canvas has moved on. Deliberately reached
// by PUBLIC, unauthenticated endpoints (see routes/api.php) — this is a
// research-sampling link handed to staff who won't be logged in, and the
// data itself (process-flow diagrams) carries no sensitive/financial/PII
// exposure risk.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flow_departments', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('title');
            $table->json('nodes');
            $table->json('edges');
            $table->string('updated_by')->nullable();
            $table->string('updated_role')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('department_key');
            $table->string('submitted_by')->nullable();
            $table->string('submitted_role')->nullable();
            $table->text('note')->nullable();
            $table->json('nodes_snapshot');
            $table->json('edges_snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->index('department_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_suggestions');
        Schema::dropIfExists('flow_departments');
    }
};
