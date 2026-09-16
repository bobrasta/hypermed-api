<?php

namespace App\Console\Commands;

use App\Services\ExpenseService;
use Illuminate\Console\Command;

class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring';
    protected $description = 'Creates the next occurrence of any recurring expense template that is due today';

    public function handle(ExpenseService $service): int
    {
        $generated = $service->generateDueRecurring();
        $this->info("Generated {$generated} recurring expense(s).");
        return self::SUCCESS;
    }
}
