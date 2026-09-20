<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Services\MachineModelNormalizer;
use Illuminate\Console\Command;

// Dry-run only, per hypermed_claude_code_prompt.md Section 4: "propose a
// one-off cleanup ... do not merge silently." This reports; it never writes.
class ReportMachineModelDuplicates extends Command
{
    protected $signature = 'machines:model-duplicates-report';
    protected $description = 'Dry-run report of existing machine models that normalize to the same key (case/spacing/punctuation only — not fuzzy)';

    public function handle(): int
    {
        $groups = Machine::query()
            ->select('model')
            ->selectRaw('count(*) as machine_count')
            ->groupBy('model')
            ->get()
            ->groupBy(fn ($row) => MachineModelNormalizer::normalize($row->model));

        $duplicates = $groups->filter(fn ($rows) => $rows->count() > 1);

        if ($duplicates->isEmpty()) {
            $this->info('No near-duplicate machine models found.');
            return self::SUCCESS;
        }

        $this->warn("{$duplicates->count()} normalized key(s) have more than one spelling on record:");
        foreach ($duplicates as $key => $rows) {
            $this->line("  \"{$key}\":");
            foreach ($rows as $row) {
                $this->line("    - \"{$row->model}\" ({$row->machine_count} machine(s))");
            }
        }
        $this->line('');
        $this->line('Nothing was changed. Merge manually via the API/UI if these should become one model.');

        return self::SUCCESS;
    }
}
