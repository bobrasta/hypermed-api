<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

// Section 12 of hypermed_claude_code_prompt.md: "The 0.5 is a configurable
// setting (Settings, default 0.5), not hardcoded." Seeds the row so it
// shows up in the Settings screen immediately rather than only existing as
// a code-level fallback the first time someone reads it.
return new class extends Migration
{
    public function up(): void
    {
        if (Setting::whereKey('machine_replacement_threshold_percent')->doesntExist()) {
            Setting::set('machine_replacement_threshold_percent', '0.5');
        }
    }

    public function down(): void
    {
        Setting::whereKey('machine_replacement_threshold_percent')->delete();
    }
};
