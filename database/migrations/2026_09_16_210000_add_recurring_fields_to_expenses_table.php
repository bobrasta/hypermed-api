<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->boolean('is_recurring')->default(false)->after('notes');
            $table->unsignedTinyInteger('recur_interval')->nullable()->after('is_recurring');
            $table->string('recur_interval_type')->nullable()->after('recur_interval'); // days|months|years
            $table->unsignedTinyInteger('recur_repeat_on')->nullable()->after('recur_interval_type'); // day-of-month, only for 'months'
            $table->unsignedSmallInteger('recur_repetitions')->nullable()->after('recur_repeat_on'); // null = indefinite
            $table->date('recur_stopped_on')->nullable()->after('recur_repetitions');
            $table->foreignId('recur_parent_id')->nullable()->after('recur_stopped_on')
                ->constrained('expenses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recur_parent_id');
            $table->dropColumn([
                'is_recurring', 'recur_interval', 'recur_interval_type',
                'recur_repeat_on', 'recur_repetitions', 'recur_stopped_on',
            ]);
        });
    }
};
