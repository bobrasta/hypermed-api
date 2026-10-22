<?php

use App\Models\NestTender;
use App\Services\Nest\RelevanceEngine;
use Database\Seeders\NestRelevanceRuleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// NeST relevance noise (2026-10-22): generic keywords ("equipment", "spare
// parts", "reagents", "x-ray"…) flagged ICT, mining, railway, fire-equipment
// and water-lab tenders. They now carry a context and only count for health
// tenders (see RelevanceEngine). Existing rules keep any weight/active edits
// made in the app; only their context is set. Then every stored tender is
// re-scored.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nest_relevance_rules', function (Blueprint $t) {
            $t->string('context', 10)->nullable()->after('weight'); // null | text | health
        });

        $now = now();
        foreach (NestRelevanceRuleSeeder::defaults() as $r) {
            if ($r['context'] !== null) {
                DB::table('nest_relevance_rules')
                    ->where('type', $r['type'])->where('tag', $r['tag'])->where('term', $r['term'])
                    ->whereNull('context')->update(['context' => $r['context'], 'updated_at' => $now]);
            }
        }

        DB::table('nest_relevance_rules')->where('type', 'keyword')
            ->whereIn('term', NestRelevanceRuleSeeder::RETIRED)->update(['active' => false, 'updated_at' => $now]);

        foreach (NestRelevanceRuleSeeder::RENAMED as $old => $new) {
            $taken = DB::table('nest_relevance_rules')->where('type', 'exclude')->where('term', $new)->exists();
            DB::table('nest_relevance_rules')->where('type', 'exclude')->where('term', $old)
                ->update($taken ? ['active' => false, 'updated_at' => $now] : ['term' => $new, 'updated_at' => $now]);
        }

        (new NestRelevanceRuleSeeder)->run();

        $engine = new RelevanceEngine;
        NestTender::query()->chunkById(200, function ($chunk) use ($engine) {
            foreach ($chunk as $t) {
                $t->fill($engine->evaluate($t->only(['description', 'sub_category_name', 'sub_category_acronym', 'procurement_category', 'procuring_entity_name'])));
                if ($t->isDirty()) {
                    $t->save();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('nest_relevance_rules', function (Blueprint $t) {
            $t->dropColumn('context');
        });
    }
};
