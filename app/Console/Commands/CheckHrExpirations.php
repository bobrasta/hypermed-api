<?php

namespace App\Console\Commands;

use App\Services\AlertEngineService;
use Illuminate\Console\Command;

class CheckHrExpirations extends Command
{
    protected $signature = 'hr:check-expirations';
    protected $description = 'Notify HR/admin of contracts nearing expiry or probation periods ending soon';

    public function handle(AlertEngineService $service): int
    {
        $sent = $service->checkAll();
        $this->info("Sent {$sent} HR expiration alert(s).");
        return self::SUCCESS;
    }
}
