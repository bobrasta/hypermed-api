<?php

namespace App\Console\Commands;

use App\Services\Nest\NestException;
use App\Services\Nest\NestSyncService;
use Illuminate\Console\Command;

class NestSync extends Command
{
    protected $signature = 'nest:sync
        {--jitter : Wait a random 0–10 minutes first (scheduled runs)}
        {--max-pages= : Fetch at most this many pages}
        {--no-notify : Do not send notifications for this run}
        {--force : Ignore the per-day run limit}';

    protected $description = 'Fetch published tenders from NeST (nest.go.tz), score relevance and notify';

    public function handle(NestSyncService $sync): int
    {
        if (! config('nest.enabled')) {
            $this->warn('NeST sync is disabled (NEST_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }
        if ($this->option('jitter')) {
            sleep(random_int(0, 600));
        }

        try {
            $run = $sync->run(
                trigger: 'cli',
                maxPages: $this->option('max-pages') ? (int) $this->option('max-pages') : null,
                notify: ! $this->option('no-notify'),
                force: (bool) $this->option('force'),
            );
        } catch (NestException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Pages {$run->pages}, records {$run->records}, new {$run->new_count} ({$run->new_relevant} relevant), changes on tracked tenders {$run->changes}.");
        if ($run->status !== 'ok') {
            $this->error('NeST sync failed: ' . $run->error);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
