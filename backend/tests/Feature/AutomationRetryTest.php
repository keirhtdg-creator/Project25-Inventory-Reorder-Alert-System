<?php

namespace Tests\Feature;

use App\Logging\AutomationLog;
use App\Services\InventoryWorkflowService;
use App\Services\StockRiskAutomationService;
use App\Services\TransientAutomationFailure;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Failures here are injected: a workflow-service double throws from recordEvent(), which runs
 * inside the weekly summary transaction. QueryException objects are constructed with real SQLSTATE
 * and driver codes, but no real deadlock, timeout or lost connection is produced by these tests.
 * The 60 s / 300 s delays are skipped by production code in the testing environment, so they are
 * not observed here.
 */
class AutomationRetryTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'S3cretPassw0rd';

    protected function setUp(): void
    {
        parent::setUp();
        config(['inventory.automation_max_attempts' => 3]);
        $item = DB::table('inventory_items')->insertGetId([
            'sku' => 'RETRY-1', 'name' => 'Retry item', 'unit' => 'piece', 'on_hand_quantity' => 0,
            'reorder_threshold' => 5, 'suggested_reorder_quantity' => 10, 'is_approved' => true,
            'created_at' => '2026-10-01 00:00:00', 'updated_at' => '2026-10-01 00:00:00',
        ]);
        DB::table('stock_transactions')->insert([
            'item_id' => $item, 'transaction_type' => 'adjustment', 'quantity_delta' => 0, 'resulting_quantity' => 1,
            'reason' => 'fixture', 'actor_label' => 'Test', 'created_at' => '2026-10-20 01:00:00', 'updated_at' => '2026-10-20 01:00:00',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_configured_retry_limits(): void
    {
        $this->assertSame([60, 300], config('inventory.automation_retry_delays'));
        $this->assertSame(3, (int) config('inventory.automation_max_attempts'));
    }

    public function test_transient_failure_then_recovery_on_second_attempt(): void
    {
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            if (++$calls === 1) {
                throw new TransientAutomationFailure('injected');
            }
        });

        $summary = $service->generateWeeklySummary($this->reference());

        $this->assertSame(2, $calls);
        $this->assertSame(1, (int) $summary->risk_item_count);
        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseCount('weekly_stock_summary_items', 1);
        $this->assertDatabaseCount('manual_actions', 0);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'attempt_count' => 2, 'status' => 'completed']);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());

        $failed = $this->events('weekly_stock_summary.attempt_failed');
        $recovered = $this->events('weekly_stock_summary.recovered');
        $generated = $this->events('weekly_stock_summary.generated');
        $this->assertCount(1, $failed);
        $this->assertSame(['warning', 1, 'retry_scheduled', 'SIMULATED_TRANSIENT_FAILURE'], [$failed[0]['level'], $failed[0]['attempt'], $failed[0]['outcome'], $failed[0]['error_code']]);
        $this->assertCount(1, $recovered);
        $this->assertSame([2, 'completed'], [$recovered[0]['attempt'], $recovered[0]['outcome']]);
        $this->assertCount(1, $generated);
        $this->assertSame(2, $generated[0]['attempt']);
        $this->assertSame($failed[0]['correlation_id'], $recovered[0]['correlation_id']);
        $this->assertSame($failed[0]['correlation_id'], $generated[0]['correlation_id']);
        $this->assertIsInt($failed[0]['duration_ms']);
        $this->assertIsInt($recovered[0]['duration_ms']);
    }

    public function test_retry_exhaustion_after_three_attempts_routes_to_manual_action_and_blocks_rerun(): void
    {
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            $calls++;
            throw new TransientAutomationFailure('injected '.self::SECRET);
        });
        $reference = $this->reference();

        try {
            $service->generateWeeklySummary($reference);
            $this->fail('Retry exhaustion must propagate the last failure.');
        } catch (TransientAutomationFailure) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertDatabaseCount('weekly_stock_summary_items', 0);
        $run = DB::table('automation_runs')->where('idempotency_key', 'weekly-stock-risk:2026-10-19')->first();
        $this->assertSame(['retry_exhausted', 3], [$run->status, (int) $run->attempt_count]);
        $this->assertSame('A simulated transient database failure was injected.', $run->last_error);
        $actions = DB::table('manual_actions')->get();
        $this->assertCount(1, $actions);
        $this->assertSame(['open', 'supervisor', (int) $run->id], [$actions[0]->status, $actions[0]->assigned_role, (int) $actions[0]->automation_run_id]);
        $this->assertStringContainsString('after 3 attempt(s)', $actions[0]->reason);
        $this->assertStringNotContainsString(self::SECRET, $actions[0]->reason.$run->last_error);

        $failed = $this->events('weekly_stock_summary.attempt_failed');
        $this->assertSame([1, 2, 3], array_column($failed, 'attempt'));
        $this->assertSame(['retry_scheduled', 'retry_scheduled', 'failed'], array_column($failed, 'outcome'));
        $routed = $this->events('weekly_stock_summary.manual_action_created');
        $this->assertCount(1, $routed);
        $this->assertSame(['error', 3, 'permanent_failure_routed'], [$routed[0]['level'], $routed[0]['attempt'], $routed[0]['outcome']]);
        $this->assertCount(1, array_unique(array_merge(array_column($failed, 'correlation_id'), array_column($routed, 'correlation_id'))));
        $this->assertStringNotContainsString(self::SECRET, file_get_contents(config('logging.channels.automation.path')));

        // Blocked while the manual action is open, without another attempt or manual action.
        try {
            $service->generateWeeklySummary($reference);
            $this->fail('Rerun must be blocked while the manual action is open.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Resolve the related manual action', $exception->getMessage());
        }
        $this->assertSame(3, $calls);
        $this->assertDatabaseCount('manual_actions', 1);

        // A human resolves the action; the next execution may run and succeeds with a healthy service.
        $this->resolveManualActions();
        $summary = app(StockRiskAutomationService::class)->generateWeeklySummary($reference);
        $this->assertSame('2026-10-19', $summary->week_start);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'completed', 'attempt_count' => 1, 'last_error' => null]);
        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('manual_actions', 1);
    }

    public function test_rerun_after_resolved_exhaustion_that_fails_again_creates_a_new_manual_action_only_once(): void
    {
        $service = $this->serviceThrowing(function (): void {
            throw new TransientAutomationFailure('injected');
        });
        $reference = $this->reference();
        $this->expectFailure($service, $reference);
        $this->resolveManualActions();
        $this->expectFailure($service, $reference);

        $this->assertSame(2, DB::table('manual_actions')->count());
        $this->assertSame(1, DB::table('manual_actions')->where('status', 'open')->count());
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertDatabaseCount('weekly_stock_summaries', 0);
    }

    public function test_non_transient_failure_is_permanent_after_one_attempt_and_is_sanitized(): void
    {
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            $calls++;
            throw new RuntimeException('connection to mysql://root:'.self::SECRET.'@127.0.0.1/db failed password='.self::SECRET);
        });
        $reference = $this->reference();

        $this->expectFailure($service, $reference, RuntimeException::class);

        $this->assertSame(1, $calls);
        $run = DB::table('automation_runs')->where('idempotency_key', 'weekly-stock-risk:2026-10-19')->first();
        $this->assertSame(['permanent_failure', 1, 'Automation failed with a non-retryable error.'], [$run->status, (int) $run->attempt_count, $run->last_error]);
        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertSame(1, DB::table('manual_actions')->where('status', 'open')->count());
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('manual_actions')->get()).json_encode($run));

        $failed = $this->events('weekly_stock_summary.attempt_failed');
        $this->assertCount(1, $failed);
        $this->assertSame(['error', 1, 'failed', 'AUTOMATION_FAILURE'], [$failed[0]['level'], $failed[0]['attempt'], $failed[0]['outcome'], $failed[0]['error_code']]);
        $routed = $this->events('weekly_stock_summary.manual_action_created');
        $this->assertCount(1, $routed);
        $this->assertSame('permanent_failure_routed', $routed[0]['outcome']);
        $raw = file_get_contents(config('logging.channels.automation.path'));
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->assertStringNotContainsString('mysql://', $raw);

        // Repeated runs stay blocked and add nothing.
        foreach ([1, 2] as $_) {
            try {
                $service->generateWeeklySummary($reference);
                $this->fail('Rerun must be blocked.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Resolve the related manual action', $exception->getMessage());
            }
        }
        $this->assertSame(1, $calls);
        $this->assertDatabaseCount('manual_actions', 1);

        $this->resolveManualActions();
        app(StockRiskAutomationService::class)->generateWeeklySummary($reference);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'completed']);
    }

    public function test_permanent_failure_records_one_workflow_event_linked_to_run_and_manual_action(): void
    {
        $service = $this->serviceThrowing(function (): void {
            throw new RuntimeException('boom '.self::SECRET);
        });
        $reference = $this->reference();
        $this->expectFailure($service, $reference, RuntimeException::class);

        $run = DB::table('automation_runs')->where('idempotency_key', 'weekly-stock-risk:2026-10-19')->first();
        $action = DB::table('manual_actions')->first();
        $events = DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->get();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame(['automation_run', (int) $run->id, 'System', null, 'permanent_failure'], [$event->subject_type, (int) $event->subject_id, $event->actor_label, $event->previous_state, $event->current_state]);
        $details = json_decode($event->details, true);
        $this->assertSame(
            ['manual_action_id' => (int) $action->id, 'automation_run_id' => (int) $run->id, 'idempotency_key' => 'weekly-stock-risk:2026-10-19', 'attempt' => 1, 'error_code' => 'AUTOMATION_FAILURE'],
            $details,
        );
        $routed = $this->events('weekly_stock_summary.manual_action_created');
        $this->assertSame($routed[0]['correlation_id'], $event->correlation_id);
        $this->assertSame($this->events('weekly_stock_summary.attempt_failed')[0]['correlation_id'], $event->correlation_id);
        $this->assertStringNotContainsString(self::SECRET, json_encode($event));
        $this->assertStringNotContainsString('boom', json_encode($event));
        $this->assertSame(0, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());

        // A blocked rerun and a rerun after resolution add no further failure event.
        $this->expectBlocked($service, $reference);
        $this->expectBlocked($service, $reference);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->count());
        $this->resolveManualActions();
        app(StockRiskAutomationService::class)->generateWeeklySummary($reference);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->count());
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());
    }

    public function test_retry_exhaustion_records_one_workflow_event_with_final_attempt(): void
    {
        $service = $this->serviceThrowing(function (): void {
            throw new TransientAutomationFailure('injected '.self::SECRET);
        });
        $reference = $this->reference();
        $this->expectFailure($service, $reference);

        $run = DB::table('automation_runs')->where('idempotency_key', 'weekly-stock-risk:2026-10-19')->first();
        $action = DB::table('manual_actions')->first();
        $events = DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->get();
        $this->assertCount(1, $events);
        $this->assertSame(['automation_run', (int) $run->id, 'retry_exhausted'], [$events[0]->subject_type, (int) $events[0]->subject_id, $events[0]->current_state]);
        $this->assertSame(
            ['manual_action_id' => (int) $action->id, 'automation_run_id' => (int) $run->id, 'idempotency_key' => 'weekly-stock-risk:2026-10-19', 'attempt' => 3, 'error_code' => 'SIMULATED_TRANSIENT_FAILURE'],
            json_decode($events[0]->details, true),
        );
        $this->assertSame($this->events('weekly_stock_summary.manual_action_created')[0]['correlation_id'], $events[0]->correlation_id);
        $this->assertStringNotContainsString(self::SECRET, json_encode($events[0]));

        $this->expectBlocked($service, $reference);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->count());
        $this->resolveManualActions();
        app(StockRiskAutomationService::class)->generateWeeklySummary($reference);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'automation.permanent_failure_routed')->count());
    }

    public function test_failure_event_and_manual_action_are_persisted_together(): void
    {
        $workflow = new class(app(AutomationLog::class)) extends InventoryWorkflowService
        {
            public function recordEvent(
                string $subjectType,
                int $subjectId,
                string $eventType,
                ?string $previousState,
                ?string $currentState,
                array $details = [],
                string $actorLabel = 'Demo user',
            ): void {
                if ($eventType !== 'weekly_stock_summary.generated') {
                    throw new RuntimeException('event store unavailable');
                }
                throw new RuntimeException('boom');
            }
        };
        $service = new StockRiskAutomationService($workflow, app(AutomationLog::class));

        $this->expectFailure($service, $this->reference(), RuntimeException::class);

        $this->assertDatabaseCount('manual_actions', 0);
        $this->assertDatabaseCount('workflow_events', 0);
        $this->assertCount(1, $this->events('weekly_stock_summary.manual_action_persistence_failed'));
    }

    private function expectBlocked(StockRiskAutomationService $service, CarbonImmutable $reference): void
    {
        try {
            $service->generateWeeklySummary($reference);
            $this->fail('Rerun must be blocked while the manual action is open.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Resolve the related manual action', $exception->getMessage());
        }
    }

    public function test_query_exception_with_permanent_sqlstate_is_not_retried(): void
    {
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            $calls++;
            throw $this->queryException('23000', 1062);
        });

        $this->expectFailure($service, $this->reference(), QueryException::class);

        $this->assertSame(1, $calls);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'permanent_failure', 'last_error' => 'Database automation query failed (SQLSTATE 23000).']);
        $failed = $this->events('weekly_stock_summary.attempt_failed');
        $this->assertSame('DATABASE_QUERY_FAILURE', $failed[0]['error_code']);
    }

    public function test_transient_database_error_is_retried_and_exhaustion_reports_retry_exhausted(): void
    {
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            $calls++;
            throw $this->queryException('40001', 1213);
        });

        $this->expectFailure($service, $this->reference(), QueryException::class);

        $this->assertSame(3, $calls);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'retry_exhausted', 'attempt_count' => 3, 'last_error' => 'Database automation query failed (SQLSTATE 40001).']);
        $this->assertCount(3, $this->events('weekly_stock_summary.attempt_failed'));
    }

    public function test_max_attempts_setting_bounds_the_retries(): void
    {
        config(['inventory.automation_max_attempts' => 2]);
        $calls = 0;
        $service = $this->serviceThrowing(function () use (&$calls): void {
            $calls++;
            throw new TransientAutomationFailure('injected');
        });

        $this->expectFailure($service, $this->reference());

        $this->assertSame(2, $calls);
        $this->assertDatabaseHas('automation_runs', ['status' => 'retry_exhausted', 'attempt_count' => 2]);
    }

    #[DataProvider('classificationCases')]
    public function test_error_classification(Closure $make, bool $transient, string $code): void
    {
        $service = $this->serviceThrowing(fn () => null);
        $exception = $make();
        $isTransient = Closure::bind(fn (\Throwable $e): bool => $this->isTransient($e), $service, StockRiskAutomationService::class);
        $errorCode = Closure::bind(fn (\Throwable $e): string => $this->errorCode($e), $service, StockRiskAutomationService::class);

        $this->assertSame($transient, $isTransient($exception));
        $this->assertSame($code, $errorCode($exception));
    }

    public static function classificationCases(): array
    {
        $query = fn (string $state, int $driver): Closure => function () use ($state, $driver): QueryException {
            $pdo = new PDOException('driver message');
            $pdo->errorInfo = [$state, $driver, 'driver message'];

            return new QueryException('mysql', 'select 1', [], $pdo);
        };

        return [
            'TransientAutomationFailure' => [fn () => new TransientAutomationFailure('x'), true, 'SIMULATED_TRANSIENT_FAILURE'],
            'SQLSTATE 40001' => [$query('40001', 0), true, 'DATABASE_QUERY_FAILURE'],
            'SQLSTATE 08S01' => [$query('08S01', 0), true, 'DATABASE_QUERY_FAILURE'],
            'SQLSTATE HYT00' => [$query('HYT00', 0), true, 'DATABASE_QUERY_FAILURE'],
            'SQLSTATE HYT01' => [$query('HYT01', 0), true, 'DATABASE_QUERY_FAILURE'],
            'driver 1205' => [$query('HY000', 1205), true, 'DATABASE_QUERY_FAILURE'],
            'driver 1213' => [$query('HY000', 1213), true, 'DATABASE_QUERY_FAILURE'],
            'driver 2002' => [$query('HY000', 2002), true, 'DATABASE_QUERY_FAILURE'],
            'driver 2003' => [$query('HY000', 2003), true, 'DATABASE_QUERY_FAILURE'],
            'driver 2006' => [$query('HY000', 2006), true, 'DATABASE_QUERY_FAILURE'],
            'driver 2013' => [$query('HY000', 2013), true, 'DATABASE_QUERY_FAILURE'],
            'constraint violation 23000' => [$query('23000', 1062), false, 'DATABASE_QUERY_FAILURE'],
            'syntax error 42000' => [$query('42000', 1064), false, 'DATABASE_QUERY_FAILURE'],
            'RuntimeException' => [fn () => new RuntimeException('x'), false, 'AUTOMATION_FAILURE'],
            'InvalidArgumentException' => [fn () => new \InvalidArgumentException('x'), false, 'AUTOMATION_FAILURE'],
            'TypeError' => [fn () => new \TypeError('x'), false, 'AUTOMATION_FAILURE'],
            'raw PDOException' => [fn () => new PDOException('x'), false, 'AUTOMATION_FAILURE'],
        ];
    }

    private function queryException(string $state, int $driver): QueryException
    {
        $pdo = new PDOException('driver message');
        $pdo->errorInfo = [$state, $driver, 'driver message'];

        return new QueryException('mysql', 'select 1', [], $pdo);
    }

    private function expectFailure(StockRiskAutomationService $service, CarbonImmutable $reference, string $class = TransientAutomationFailure::class): void
    {
        try {
            $service->generateWeeklySummary($reference);
            $this->fail('A failure was expected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($class, $exception);
        }
    }

    private function resolveManualActions(): void
    {
        DB::table('manual_actions')->where('status', 'open')->update(['status' => 'resolved', 'updated_at' => now()]);
    }

    private function reference(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-26 08:00:00', 'Asia/Manila');
    }

    /** @return list<array<string, mixed>> */
    private function events(string $name): array
    {
        return array_values(array_filter($this->automationLogEntries(), fn (array $entry): bool => $entry['event'] === $name));
    }

    private function serviceThrowing(Closure $onRecordEvent): StockRiskAutomationService
    {
        $workflow = new class(app(AutomationLog::class), $onRecordEvent) extends InventoryWorkflowService
        {
            public function __construct(AutomationLog $log, private readonly Closure $hook)
            {
                parent::__construct($log);
            }

            public function recordEvent(
                string $subjectType,
                int $subjectId,
                string $eventType,
                ?string $previousState,
                ?string $currentState,
                array $details = [],
                string $actorLabel = 'Demo user',
            ): void {
                if ($eventType === 'weekly_stock_summary.generated') {
                    ($this->hook)();
                }
                parent::recordEvent($subjectType, $subjectId, $eventType, $previousState, $currentState, $details, $actorLabel);
            }
        };

        return new StockRiskAutomationService($workflow, app(AutomationLog::class));
    }
}
