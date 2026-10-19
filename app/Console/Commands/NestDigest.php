<?php

namespace App\Console\Commands;

use App\Services\Nest\NestNotifier;
use Illuminate\Console\Command;

class NestDigest extends Command
{
    protected $signature = 'nest:digest';

    protected $description = 'Notify about relevant NeST tenders closing within 3 days that are still new or under review';

    public function handle(NestNotifier $notifier): int
    {
        $n = $notifier->sendDigest();
        $this->info("Digest covered {$n} tender(s).");

        return self::SUCCESS;
    }
}
