<?php

namespace App\Jobs;

use App\Services\Nest\NestException;
use App\Services\Nest\NestSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** "Check NeST now" from the app — same run as the schedule, counted in the daily limit. */
class RunNestSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;
    public int $tries = 1;

    public function __construct(public ?int $userId = null) {}

    public function handle(NestSyncService $sync): void
    {
        try {
            $sync->run(trigger: 'manual', userId: $this->userId);
        } catch (NestException) {
            // Limit reached or a run already going — nothing to do.
        }
    }
}
