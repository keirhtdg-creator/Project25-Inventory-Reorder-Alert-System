<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executes the real Laravel scheduler (`schedule:run`) in a separate process against the
 * dedicated disposable MariaDB database. The scheduler starts the real `php artisan inventory:*`
 * commands as child processes, which write to the database and the automation log.
 *
 * Only the harness process has a fixed clock; the child commands use the real clock, so test
 * records are created relative to the real current time. Runs only when
 * CONCURRENCY_DB_DATABASE=inventory_reorder_alerts_concurrency_test; the project database is never used.
 * The Laravel cache store is overridden to `database` (process-scoped) so overlap locks live in the
 * disposable database. The project's own .env uses the `file` store.
 */
class SchedulerExecutionTest extends TestCase
{
    private const TEST_DATABASE = 'inventory_reorder_alerts_concurrency_test';

    private const TABLES = [
        'inventory_items', 'stock_transactions', 'inventory_alerts', 'reorder_requests', 'workflow_events',
        'escalation_events', 'manual_actions', 'automation_runs', 'weekly_stock_summaries', 'weekly_stock_summary_items',
    ];

    private string $previousConnection;

    /** @var array<string, int> */
    private array $maxIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONCURRENCY_DB_DATABASE') !== self::TEST_DATABASE) {
            $this->markTestSkipped('Set CONCURRENCY_DB_DATABASE='.self::TEST_DATABASE.' to run the scheduler execution test.');
        }

        config(['database.connections.concurrency' => array_merge(
            config('database.connections.mysql'),
            ['database' => self::TEST_DATABASE, 'url' => null],
        )]);
        $this->previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('concurrency');

        $this->assertSame(self::TEST_DATABASE, DB::selectOne('select database() as name')->name);
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table must be empty before the scheduler test");
            $this->maxIds[$table] = (int) DB::table($table)->max('id');
        }
        $this->assertSame(0, $this->lockRowCount(), 'No scheduler locks may exist before the test');
    }

    protected function tearDown(): void
    {
        if (isset($this->previousConnection)) {
            // The disposable database was empty at the start, so everything present now was created by this test.
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach (['weekly_stock_summary_items', 'weekly_stock_summaries', 'manual_actions', 'escalation_events', 'workflow_events', 'automation_runs', 'reorder_requests', 'inventory_alerts', 'stock_transactions', 'inventory_items'] as $table) {
                DB::table($table)->where('id', '>', $this->maxIds[$table])->delete();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::table('cache_locks')->where('key', 'like', '%schedule-%')->delete();
            DB::table('cache')->where('key', 'like', '%schedule-%')->delete();
            DB::setDefaultConnection($this->previousConnection);
            DB::purge('concurrency');
        }

        parent::tearDown();
    }

    public function test_scheduler_runs_the_daily_aging_command_only_when_due(): void
    {
        $realNow = CarbonImmutable::now('UTC');
        [$agedId] = $this->reorder('SCHED-AGED', $realNow->subDays(8));
        [$freshId] = $this->reorder('SCHED-FRESH', $realNow->subDays(2));

        foreach (['2026-10-27 07:59:00', '2026-10-27 08:01:00', '2026-10-27 20:00:00'] as $wrongTime) {
            $result = $this->harness('run', $wrongTime);
            $this->assertSame(0, $result['exit_code'], $wrongTime);
            $this->assertStringContainsString('No scheduled commands are ready to run.', $result['output'], $wrongTime);
        }
        $this->assertSame(0, DB::table('escalation_events')->count());
        $this->assertSame([], $this->logEntries());

        $due = $this->harness('run', '2026-10-27 08:00:00');
        $this->assertSame(0, $due['exit_code'], $due['output']);
        $this->assertStringContainsString('inventory:process-aging', $due['output']);
        $this->assertStringContainsString('Running', $due['output']);
        $this->assertStringNotContainsString('inventory:weekly-risk', $due['output']);

        $this->assertSame(1, DB::table('escalation_events')->where('reorder_request_id', $agedId)->count());
        $this->assertSame(0, DB::table('escalation_events')->where('reorder_request_id', $freshId)->count());
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => "aging:reorder:{$agedId}:level:1", 'status' => 'completed'], 'concurrency');
        $this->assertSame(1, DB::table('manual_actions')->where('reorder_request_id', $agedId)->count());
        $this->assertSame(1, DB::table('workflow_events')->where('subject_id', $agedId)->where('event_type', 'reorder.aging_escalated')->count());
        $this->assertSame(0, DB::table('weekly_stock_summaries')->count());

        $scan = $this->entries('reorder.aging_scan_completed');
        $this->assertCount(1, $scan);
        $this->assertSame([1, 'info'], [$scan[0]['escalated_count'], $scan[0]['level']]);
        $this->assertIsInt($scan[0]['duration_ms']);
        $escalated = $this->entries('reorder.aging_escalated');
        $this->assertCount(1, $escalated);
        $this->assertSame($scan[0]['correlation_id'], $escalated[0]['correlation_id']);
        $this->assertSame($agedId, $escalated[0]['reorder_request_id']);
        $this->assertSame('escalated', $escalated[0]['outcome']);
        $this->assertSame(['result' => false], ['result' => $this->harness('exists', 'inventory:process-aging')['result']], 'lock released after the run');

        // The scheduler fires again in the same minute; the command runs but is idempotent.
        $again = $this->harness('run', '2026-10-27 08:00:00');
        $this->assertStringContainsString('inventory:process-aging', $again['output']);
        $this->assertSame(1, DB::table('escalation_events')->count());
        $this->assertSame(1, DB::table('manual_actions')->count());
        $this->assertSame([1, 0], array_column($this->entries('reorder.aging_scan_completed'), 'escalated_count'));
        $this->assertLogIsSafe();
    }

    public function test_scheduler_runs_the_weekly_summary_only_monday_0800_manila(): void
    {
        $week = CarbonImmutable::now('Asia/Manila')->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $weekStart = $week->toDateString();
        $item = DB::table('inventory_items')->insertGetId($this->itemRow('SCHED-WEEKLY', $week->subDays(30)->utc()));
        DB::table('stock_transactions')->insert($this->transactionRow($item, 1, $week->addDay()->utc()));

        $wrongTimes = [
            'Monday 07:59' => '2026-10-26 07:59:00',
            'Monday 08:01' => '2026-10-26 08:01:00',
            'Tuesday 08:00' => '2026-10-27 08:00:00',
            'Sunday 08:00' => '2026-10-25 08:00:00',
            'Monday 08:00 UTC' => '2026-10-26 16:00:00',
        ];
        foreach ($wrongTimes as $label => $time) {
            $result = $this->harness('run', $time);
            $this->assertStringNotContainsString('inventory:weekly-risk', $result['output'], $label);
        }
        $this->assertSame(0, DB::table('weekly_stock_summaries')->count());
        $this->assertSame([], $this->entries('weekly_stock_summary.generated'));

        $due = $this->harness('run', '2026-10-26 08:00:00');
        $this->assertSame(0, $due['exit_code'], $due['output']);
        $this->assertStringContainsString('inventory:weekly-risk', $due['output']);

        $summary = DB::table('weekly_stock_summaries')->first();
        $this->assertSame([$weekStart, $week->addDays(6)->toDateString(), 1], [$summary->week_start, $summary->week_end, (int) $summary->risk_item_count]);
        $this->assertSame(1, DB::table('weekly_stock_summary_items')->where('summary_id', $summary->id)->where('item_id', $item)->count());
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => "weekly-stock-risk:$weekStart", 'status' => 'completed', 'attempt_count' => 1], 'concurrency');
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());

        $generated = $this->entries('weekly_stock_summary.generated');
        $this->assertCount(1, $generated);
        $this->assertSame(["weekly-stock-risk:$weekStart", 1, 'completed', 1, $weekStart], [
            $generated[0]['idempotency_key'], $generated[0]['attempt'], $generated[0]['outcome'], $generated[0]['risk_item_count'], $generated[0]['week_start'],
        ]);
        $this->assertIsInt($generated[0]['automation_run_id']);
        $this->assertIsInt($generated[0]['duration_ms']);
        $this->assertNotEmpty($generated[0]['correlation_id']);
        $this->assertFalse($this->harness('exists', 'inventory:weekly-risk')['result'], 'lock released after the run');

        $this->harness('run', '2026-10-26 08:00:00');
        $this->assertSame(1, DB::table('weekly_stock_summaries')->count());
        $this->assertSame(1, DB::table('weekly_stock_summary_items')->count());
        $this->assertSame(1, DB::table('automation_runs')->where('automation_type', 'weekly_stock_summary')->count());
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());
        $this->assertLogIsSafe();
    }

    public function test_held_overlap_lock_makes_the_scheduler_skip_the_command_until_it_is_released(): void
    {
        $week = CarbonImmutable::now('Asia/Manila')->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
        $item = DB::table('inventory_items')->insertGetId($this->itemRow('SCHED-LOCK', $week->subDays(30)->utc()));
        DB::table('stock_transactions')->insert($this->transactionRow($item, 1, $week->addDay()->utc()));

        $this->assertTrue($this->harness('lock', 'inventory:weekly-risk')['result'], 'lock acquired by a separate process');
        $this->assertTrue($this->harness('exists', 'inventory:weekly-risk')['result']);
        $this->assertGreaterThan(0, $this->lockRowCount());

        $skipped = $this->harness('run', '2026-10-26 08:00:00');
        $this->assertStringNotContainsString('inventory:weekly-risk', $skipped['output']);
        $this->assertSame(0, DB::table('weekly_stock_summaries')->count());
        $this->assertSame([], $this->entries('weekly_stock_summary.generated'));
        $this->assertTrue($this->harness('exists', 'inventory:weekly-risk')['result'], 'a skipped run must not release a lock it does not own');

        $this->harness('unlock', 'inventory:weekly-risk');
        $this->assertFalse($this->harness('exists', 'inventory:weekly-risk')['result']);

        $ran = $this->harness('run', '2026-10-26 08:00:00');
        $this->assertStringContainsString('inventory:weekly-risk', $ran['output']);
        $this->assertSame(1, DB::table('weekly_stock_summaries')->count());
        $this->assertFalse($this->harness('exists', 'inventory:weekly-risk')['result']);
    }

    private function lockRowCount(): int
    {
        return DB::table('cache_locks')->where('key', 'like', '%schedule-%')->count()
            + DB::table('cache')->where('key', 'like', '%schedule-%')->count();
    }

    /** @return array<string, mixed> */
    private function harness(string $action, string $argument): array
    {
        $log = $this->automationLogPath;
        $command = [PHP_BINARY, base_path('tests/Support/scheduler_harness.php'), $action, $argument];
        $environment = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => self::TEST_DATABASE,
            'DB_URL' => '',
            'CACHE_STORE' => 'database',
            'APP_ENV' => 'local',
            'INVENTORY_SUPERVISOR_OWNER' => 'scheduler-test@example.test',
            'LOG_AUTOMATION_PATH' => $log,
        ]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        proc_close($process);

        $decoded = json_decode($stdout, true);
        $this->assertIsArray($decoded, "Harness output was not JSON: $stdout $stderr");
        $this->assertTrue($decoded['ok'], $stdout);

        return $decoded;
    }

    /** @return array{0: int, 1: int} reorder request id and item id */
    private function reorder(string $sku, CarbonImmutable $changedAtUtc): array
    {
        $itemId = DB::table('inventory_items')->insertGetId($this->itemRow($sku, $changedAtUtc->subDay()));
        DB::table('stock_transactions')->insert($this->transactionRow($itemId, 1, $changedAtUtc));
        $alertId = DB::table('inventory_alerts')->insertGetId([
            'item_id' => $itemId, 'episode_number' => 1, 'status' => 'open', 'triggered_at' => $changedAtUtc,
            'created_at' => $changedAtUtc, 'updated_at' => $changedAtUtc,
        ]);
        $requestId = DB::table('reorder_requests')->insertGetId([
            'item_id' => $itemId, 'source_alert_id' => $alertId, 'suggested_quantity' => 10, 'status' => 'pending_review',
            'status_changed_at' => $changedAtUtc, 'created_at' => $changedAtUtc, 'updated_at' => $changedAtUtc,
        ]);

        return [$requestId, $itemId];
    }

    /** @return array<string, mixed> */
    private function itemRow(string $sku, CarbonImmutable $createdAtUtc): array
    {
        return [
            'sku' => $sku, 'name' => $sku, 'unit' => 'piece', 'on_hand_quantity' => 1, 'reorder_threshold' => 5,
            'suggested_reorder_quantity' => 10, 'is_approved' => true, 'created_at' => $createdAtUtc, 'updated_at' => $createdAtUtc,
        ];
    }

    /** @return array<string, mixed> */
    private function transactionRow(int $itemId, float $resulting, CarbonImmutable $atUtc): array
    {
        return [
            'item_id' => $itemId, 'transaction_type' => 'adjustment', 'quantity_delta' => 0, 'resulting_quantity' => $resulting,
            'reason' => 'Scheduler test fixture', 'actor_label' => 'Test', 'created_at' => $atUtc, 'updated_at' => $atUtc,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function logEntries(): array
    {
        return $this->automationLogEntries();
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $event): array
    {
        return array_values(array_filter($this->logEntries(), fn (array $entry): bool => $entry['event'] === $event));
    }

    private function assertLogIsSafe(): void
    {
        $raw = file_get_contents($this->automationLogPath);
        foreach (explode("\n", trim($raw)) as $line) {
            $this->assertIsArray(json_decode($line, true), "Invalid JSON log line: $line");
        }
        $password = (string) config('database.connections.mysql.password');
        if ($password !== '') {
            $this->assertStringNotContainsString($password, $raw);
        }
        $this->assertStringNotContainsString('scheduler-test@example.test', $raw);
    }
}
