<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Profile redesign follow-up: a self-authored bio and a freeform list of
// qualification/credential badges (degree, specialization, years of
// experience — whatever the user chooses to enter), shown on the identity
// header card. Deliberately self-service free text, not a structured
// degree/institution/year schema — nothing in the spec asked for a
// verifiable credentials system, just editable "wording a person can enter".
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('bio')->nullable()->after('avatar_path');
            $table->json('qualifications')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['bio', 'qualifications']);
        });
    }
};
