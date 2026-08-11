<?php

namespace App\Console\Commands;

use App\Services\LeadFollowUpService;
use Illuminate\Console\Command;

class SendLeadFollowUpReminders extends Command
{
    protected $signature = 'leads:follow-up-reminders';
    protected $description = 'Notify sales reps whose leads have a follow-up date due today or earlier';

    public function handle(LeadFollowUpService $service): int
    {
        $sent = $service->sendDueReminders();
        $this->info("Sent {$sent} follow-up reminder(s).");
        return self::SUCCESS;
    }
}
