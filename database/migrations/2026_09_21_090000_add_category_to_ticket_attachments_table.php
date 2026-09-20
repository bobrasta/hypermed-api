<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            // Nullable: existing/generic attachments stay untagged. 'service_report'
            // is the only value written today — resolve() requires at least one
            // before a ticket can transition to resolved (Section 3 of
            // hypermed_claude_code_prompt.md).
            $table->string('category')->nullable()->after('mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_attachments', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
