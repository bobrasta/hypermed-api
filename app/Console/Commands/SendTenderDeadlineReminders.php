<?php

namespace App\Console\Commands;

use App\Services\Tender\TenderDeadlineService;
use Illuminate\Console\Command;

class SendTenderDeadlineReminders extends Command
{
    protected $signature = 'tenders:deadline-reminders';
    protected $description = 'Send 7-day, 3-day, day-of and overdue reminders for tender deadlines and device registration renewals';

    public function handle(TenderDeadlineService $service): int
    {
        $sent = $service->sendReminders();
        $this->info("Sent {$sent} deadline reminder(s).");

        return self::SUCCESS;
    }
}
