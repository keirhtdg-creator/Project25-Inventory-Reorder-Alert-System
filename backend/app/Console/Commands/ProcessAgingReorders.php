<?php

namespace App\Console\Commands;

use App\Services\StockRiskAutomationService;
use Illuminate\Console\Command;

class ProcessAgingReorders extends Command
{
    protected $signature = 'inventory:process-aging';

    protected $description = 'Escalate reorder requests that have exceeded the aging limit.';

    public function handle(StockRiskAutomationService $automation): int
    {
        $count = $automation->processAging();
        $this->info("Processed {$count} aging escalation(s).");

        return self::SUCCESS;
    }
}
