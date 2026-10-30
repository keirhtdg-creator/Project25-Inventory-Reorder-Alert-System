<?php

namespace App\Console\Commands;

use App\Services\StockRiskAutomationService;
use Illuminate\Console\Command;

class GenerateWeeklyStockRisk extends Command
{
    protected $signature = 'inventory:weekly-risk {--simulate-transient : Simulate one local/test-only transient failure}';

    protected $description = 'Generate the completed week’s stock-risk summary.';

    public function handle(StockRiskAutomationService $automation): int
    {
        $simulate = (bool) $this->option('simulate-transient');
        if ($simulate && ! app()->environment(['local', 'testing'])) {
            $this->error('Failure simulation is available only in local or testing environments.');

            return self::FAILURE;
        }

        try {
            $summary = $automation->generateWeeklySummary(simulateTransientFailure: $simulate);
        } catch (\Throwable $exception) {
            $this->error('Weekly summary failed. Review the manual-action queue and application logs.');
            report($exception);

            return self::FAILURE;
        }

        $this->info("Generated week {$summary->week_start} to {$summary->week_end}: {$summary->risk_item_count} at-risk item(s).");

        return self::SUCCESS;
    }
}
