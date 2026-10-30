<?php

namespace App\Services;

use App\Logging\AutomationLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class StockRiskAutomationService
{
    private const ACTIVE_STATUSES = [
        'pending_review',
        'approved',
        'ordered',
        'partially_received',
        'received',
    ];

    public function __construct(
        private readonly InventoryWorkflowService $workflow,
        private readonly AutomationLog $log,
    ) {}

    public function processAging(): int
    {
        $this->log->begin();
        $scanTimer = $this->log->startTimer();

        try {
            $escalatedCount = $this->escalateAgedReorders();
            $this->log->write('info', 'reorder.aging_scan_completed', [
                'outcome' => 'completed',
                'escalated_count' => $escalatedCount,
                'duration_ms' => $this->log->elapsedMs($scanTimer),
            ]);

            return $escalatedCount;
        } catch (Throwable $exception) {
            $this->log->write('error', 'reorder.aging_scan_failed', [
                'outcome' => 'failed',
                'error_code' => $this->errorCode($exception),
                'error' => $this->safeErrorSummary($exception),
                'duration_ms' => $this->log->elapsedMs($scanTimer),
            ]);

            throw $exception;
        } finally {
            $this->log->end();
        }
    }

    private function escalateAgedReorders(): int
    {
        $cutoff = now()->subDays(config('inventory.aging_days', 7));
        $agedRequests = DB::table('reorder_requests')
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where('status_changed_at', '<=', $cutoff)
            ->orderBy('id')
            ->get();
        $escalatedCount = 0;

        foreach ($agedRequests as $reorder) {
            $idempotencyKey = 'aging:reorder:'.$reorder->id.':level:1';
            $alreadyEscalated = DB::table('escalation_events')
                ->where('reorder_request_id', $reorder->id)
                ->where('escalation_level', 1)
                ->exists();
            if ($alreadyEscalated) {
                continue;
            }

            $escalationTimer = $this->log->startTimer();
            DB::transaction(function () use ($reorder, $idempotencyKey, $escalationTimer): void {
                $now = now();
                $missingOwner = blank(config('inventory.supervisor_owner'));
                $reason = $missingOwner
                    ? 'Permanent automation failure: no supervisor owner is configured. Assign an owner and review this aged reorder.'
                    : 'Reorder has remained in its current state for at least '.config('inventory.aging_days', 7).' calendar days.';

                $runId = DB::table('automation_runs')->insertGetId([
                    'automation_type' => 'aging_escalation',
                    'idempotency_key' => $idempotencyKey,
                    'attempt_count' => 1,
                    'status' => $missingOwner ? 'permanent_failure' : 'completed',
                    'last_error' => $missingOwner ? $reason : null,
                    'started_at' => $now,
                    'finished_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('escalation_events')->insert([
                    'reorder_request_id' => $reorder->id,
                    'escalation_level' => 1,
                    'reason' => $reason,
                    'escalated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $actionId = DB::table('manual_actions')->insertGetId([
                    'reorder_request_id' => $reorder->id,
                    'automation_run_id' => $runId,
                    'assigned_role' => 'supervisor',
                    'reason' => $reason,
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->workflow->recordEvent(
                    'reorder_request',
                    (int) $reorder->id,
                    $missingOwner ? 'automation.permanent_failure_routed' : 'reorder.aging_escalated',
                    $reorder->status,
                    $reorder->status,
                    ['manual_action_id' => $actionId, 'automation_run_id' => $runId],
                    'System',
                );

                $this->log->write($missingOwner ? 'error' : 'warning', $missingOwner
                    ? 'automation.aging_permanent_failure_manual_action_created'
                    : 'reorder.aging_escalated', [
                        'automation_run_id' => $runId,
                        'idempotency_key' => $idempotencyKey,
                        'reorder_request_id' => $reorder->id,
                        'inventory_item_id' => $reorder->item_id,
                        'manual_action_id' => $actionId,
                        'attempt' => 1,
                        'outcome' => $missingOwner ? 'permanent_failure_routed' : 'escalated',
                        'error_code' => $missingOwner ? 'SUPERVISOR_OWNER_NOT_CONFIGURED' : null,
                        'duration_ms' => $this->log->elapsedMs($escalationTimer),
                    ]);
            });
            $escalatedCount++;
        }

        return $escalatedCount;
    }

    public function generateWeeklySummary(?CarbonImmutable $reference = null, bool $simulateTransientFailure = false): object
    {
        $this->log->begin();

        try {
            return $this->runWeeklySummary($reference, $simulateTransientFailure);
        } finally {
            $this->log->end();
        }
    }

    private function runWeeklySummary(?CarbonImmutable $reference, bool $simulateTransientFailure): object
    {
        $runTimer = $this->log->startTimer();
        $timezone = config('inventory.timezone', 'Asia/Manila');
        $reference ??= CarbonImmutable::now($timezone);
        $weekStart = $reference->setTimezone($timezone)->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $weekEnd = $weekStart->addDays(6);
        $weekStartDate = $weekStart->toDateString();
        $weekEndDate = $weekEnd->toDateString();
        $idempotencyKey = 'weekly-stock-risk:'.$weekStartDate;

        $previousRun = DB::table('automation_runs')->where('idempotency_key', $idempotencyKey)->first();
        if ($previousRun?->status === 'completed') {
            return DB::table('weekly_stock_summaries')
                ->where('week_start', $weekStartDate)
                ->where('week_end', $weekEndDate)
                ->firstOrFail();
        }
        if (in_array($previousRun?->status, ['permanent_failure', 'retry_exhausted'], true)) {
            $hasOpenManualAction = DB::table('manual_actions')
                ->where('automation_run_id', $previousRun->id)
                ->where('status', 'open')
                ->exists();
            if ($hasOpenManualAction) {
                throw new RuntimeException('Resolve the related manual action before retrying this weekly summary.');
            }
        }

        $attemptLimit = max(1, (int) config('inventory.automation_max_attempts', 3));
        $lastError = null;
        for ($attempt = 1; $attempt <= $attemptLimit; $attempt++) {
            $attemptTimer = $this->log->startTimer();
            try {
                $summary = DB::transaction(function () use (
                    $attempt,
                    $idempotencyKey,
                    $weekStartDate,
                    $weekEndDate,
                    $weekEnd,
                    $lastError,
                    $simulateTransientFailure,
                ): object {
                    $now = now();
                    $run = DB::table('automation_runs')->where('idempotency_key', $idempotencyKey)->first();
                    if ($run) {
                        DB::table('automation_runs')->where('id', $run->id)->update([
                            'automation_type' => 'weekly_stock_summary',
                            'attempt_count' => $attempt,
                            'status' => 'completed',
                            'last_error' => $lastError,
                            'finished_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $runId = (int) $run->id;
                    } else {
                        $runId = DB::table('automation_runs')->insertGetId([
                            'automation_type' => 'weekly_stock_summary',
                            'idempotency_key' => $idempotencyKey,
                            'attempt_count' => $attempt,
                            'status' => 'completed',
                            'last_error' => $lastError,
                            'started_at' => $now,
                            'finished_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $summary = DB::table('weekly_stock_summaries')
                        ->where('week_start', $weekStartDate)
                        ->where('week_end', $weekEndDate)
                        ->first();
                    if ($summary) {
                        DB::table('weekly_stock_summary_items')->where('summary_id', $summary->id)->delete();
                        DB::table('weekly_stock_summaries')->where('id', $summary->id)->update([
                            'risk_item_count' => 0,
                            'generated_at' => $now,
                            'updated_at' => $now,
                        ]);
                        $summaryId = (int) $summary->id;
                    } else {
                        $summaryId = DB::table('weekly_stock_summaries')->insertGetId([
                            'week_start' => $weekStartDate,
                            'week_end' => $weekEndDate,
                            'risk_item_count' => 0,
                            'generated_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    if ($simulateTransientFailure && $attempt === 1 && app()->environment(['local', 'testing'])) {
                        throw new TransientAutomationFailure('Simulated one-time database lock timeout.');
                    }

                    $periodEndUtc = $weekEnd->endOfDay()->utc();
                    $riskCount = 0;
                    $items = DB::table('inventory_items')
                        ->where('is_approved', true)
                        ->where('created_at', '<=', $periodEndUtc)
                        ->orderBy('id')
                        ->get();
                    foreach ($items as $item) {
                        $quantity = DB::table('stock_transactions')
                            ->where('item_id', $item->id)
                            ->where('created_at', '<=', $periodEndUtc)
                            ->orderByDesc('id')
                            ->value('resulting_quantity');
                        if ($quantity === null) {
                            continue;
                        }
                        if ((float) $quantity <= (float) $item->reorder_threshold) {
                            $riskCount++;
                        }
                        $reorderStatus = DB::table('reorder_requests')
                            ->where('item_id', $item->id)
                            ->whereIn('status', self::ACTIVE_STATUSES)
                            ->orderByDesc('id')
                            ->value('status');
                        DB::table('weekly_stock_summary_items')->insert([
                            'summary_id' => $summaryId,
                            'item_id' => $item->id,
                            'on_hand_quantity' => $quantity,
                            'reorder_threshold' => $item->reorder_threshold,
                            'reorder_status' => $reorderStatus,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('weekly_stock_summaries')->where('id', $summaryId)->update([
                        'risk_item_count' => $riskCount,
                        'updated_at' => $now,
                    ]);
                    $this->workflow->recordEvent(
                        'weekly_stock_summary',
                        $summaryId,
                        'weekly_stock_summary.generated',
                        null,
                        'completed',
                        [
                            'week_start' => $weekStartDate,
                            'week_end' => $weekEndDate,
                            'risk_item_count' => $riskCount,
                            'attempt' => $attempt,
                        ],
                        'System',
                    );

                    return DB::table('weekly_stock_summaries')->where('id', $summaryId)->firstOrFail();
                });
                $runId = DB::table('automation_runs')->where('idempotency_key', $idempotencyKey)->value('id');
                $context = [
                    'automation_run_id' => $runId,
                    'idempotency_key' => $idempotencyKey,
                    'week_start' => $weekStartDate,
                    'week_end' => $weekEndDate,
                    'risk_item_count' => $summary->risk_item_count,
                    'attempt' => $attempt,
                    'outcome' => 'completed',
                    'duration_ms' => $this->log->elapsedMs($attemptTimer),
                ];
                $this->log->write('info', 'weekly_stock_summary.generated', $context);
                if ($attempt > 1) {
                    $this->log->write('info', 'weekly_stock_summary.recovered', $context);
                }

                return $summary;
            } catch (Throwable $exception) {
                $lastError = $this->safeErrorSummary($exception);
                $transient = $this->isTransient($exception);
                $this->log->write($transient ? 'warning' : 'error', 'weekly_stock_summary.attempt_failed', [
                    'idempotency_key' => $idempotencyKey,
                    'attempt' => $attempt,
                    'outcome' => $transient && $attempt < $attemptLimit ? 'retry_scheduled' : 'failed',
                    'error_code' => $this->errorCode($exception),
                    'error' => $lastError,
                    'duration_ms' => $this->log->elapsedMs($attemptTimer),
                ]);

                if ($transient && $attempt < $attemptLimit) {
                    if ($simulateTransientFailure) {
                        $simulateTransientFailure = false;
                    } else {
                        $delay = (int) (config('inventory.automation_retry_delays')[$attempt - 1] ?? 0);
                        if ($delay > 0 && ! app()->environment('testing')) {
                            sleep($delay);
                        }
                    }

                    continue;
                }

                $this->recordFailedRun($idempotencyKey, $attempt, $exception, $transient, $runTimer);
                throw $exception;
            }
        }

        throw new RuntimeException('Weekly stock summary exhausted its retry limit.');
    }

    private function recordFailedRun(string $idempotencyKey, int $attempt, Throwable $exception, bool $transient, int $runTimer): void
    {
        try {
            DB::transaction(function () use ($idempotencyKey, $attempt, $exception, $transient, $runTimer): void {
                $now = now();
                $existing = DB::table('automation_runs')->where('idempotency_key', $idempotencyKey)->first();
                $runValues = [
                    'automation_type' => 'weekly_stock_summary',
                    'attempt_count' => $attempt,
                    'status' => $transient ? 'retry_exhausted' : 'permanent_failure',
                    'last_error' => $this->safeErrorSummary($exception),
                    'finished_at' => $now,
                    'updated_at' => $now,
                ];
                if ($existing) {
                    DB::table('automation_runs')->where('id', $existing->id)->update($runValues);
                    $runId = (int) $existing->id;
                } else {
                    $runId = DB::table('automation_runs')->insertGetId([
                        ...$runValues,
                        'idempotency_key' => $idempotencyKey,
                        'started_at' => $now,
                        'created_at' => $now,
                    ]);
                }
                $actionId = DB::table('manual_actions')->insertGetId([
                    'automation_run_id' => $runId,
                    'assigned_role' => 'supervisor',
                    'reason' => 'Weekly stock-risk summary failed after '.$attempt.' attempt(s): '.$runValues['last_error'],
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->workflow->recordEvent(
                    'automation_run',
                    $runId,
                    'automation.permanent_failure_routed',
                    null,
                    $runValues['status'],
                    [
                        'manual_action_id' => $actionId,
                        'automation_run_id' => $runId,
                        'idempotency_key' => $idempotencyKey,
                        'attempt' => $attempt,
                        'error_code' => $this->errorCode($exception),
                    ],
                    'System',
                );
                $this->log->write('error', 'weekly_stock_summary.manual_action_created', [
                    'automation_run_id' => $runId,
                    'idempotency_key' => $idempotencyKey,
                    'attempt' => $attempt,
                    'outcome' => 'permanent_failure_routed',
                    'error_code' => $this->errorCode($exception),
                    'error' => $runValues['last_error'],
                    'duration_ms' => $this->log->elapsedMs($runTimer),
                ]);
            });
        } catch (Throwable $persistenceException) {
            $this->log->write('critical', 'weekly_stock_summary.manual_action_persistence_failed', [
                'idempotency_key' => $idempotencyKey,
                'attempt' => $attempt,
                'outcome' => 'persistence_failed',
                'error_code' => $this->errorCode($exception),
                'persistence_error_code' => $this->errorCode($persistenceException),
                'duration_ms' => $this->log->elapsedMs($runTimer),
            ]);
        }
    }

    private function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof TransientAutomationFailure) {
            return true;
        }
        if (! $exception instanceof QueryException) {
            return false;
        }

        $sqlState = $exception->errorInfo[0] ?? null;

        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['40001', '08S01', 'HYT00', 'HYT01'], true)
            || in_array($driverCode, [1205, 1213, 2002, 2003, 2006, 2013], true);
    }

    private function errorCode(Throwable $exception): string
    {
        return $exception instanceof TransientAutomationFailure
            ? 'SIMULATED_TRANSIENT_FAILURE'
            : ($exception instanceof QueryException ? 'DATABASE_QUERY_FAILURE' : 'AUTOMATION_FAILURE');
    }

    private function safeErrorSummary(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $sqlState = $exception->errorInfo[0] ?? 'unknown';

            return 'Database automation query failed (SQLSTATE '.$sqlState.').';
        }

        return $exception instanceof TransientAutomationFailure
            ? 'A simulated transient database failure was injected.'
            : 'Automation failed with a non-retryable error.';
    }
}

class TransientAutomationFailure extends RuntimeException {}
