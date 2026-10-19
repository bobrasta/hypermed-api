<?php

namespace App\Jobs;

use App\Services\Nest\NestSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Re-scores every stored NeST tender after a rule or threshold change. */
class RecomputeNestRelevance implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 60;

    public function handle(NestSyncService $sync): void
    {
        $sync->recomputeAll();
    }
}
