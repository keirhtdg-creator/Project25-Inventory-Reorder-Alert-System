<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Real concurrency test: two separate PHP processes, each with its own MariaDB connection,
 * call the actual InventoryWorkflowService against the same item at the same moment.
 *
 * It runs only when CONCURRENCY_DB_DATABASE is set to the dedicated disposable database,
 * so the default (SQLite) test suite skips it and the project database is never touched.
 */
class MariaDbConcurrencyTest extends TestCase
{
    private const TEST_DATABASE = 'inventory_reorder_alerts_concurrency_test';

    private const HOLD_MS = 500;

    private string $previousConnection;

    /** @var array<int, int> */
    private array $createdItemIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONCURRENCY_DB_DATABASE') !== self::TEST_DATABASE) {
            $this->markTestSkipped('Set CONCURRENCY_DB_DATABASE='.self::TEST_DATABASE.' to run the MariaDB concurrency test.');
        }

        config(['database.connections.concurrency' => array_merge(
            config('database.connections.mysql'),
            ['database' => self::TEST_DATABASE, 'url' => null],
        )]);
        $this->previousConnection = DB::getDefaultConnection();
        DB::setDefaultConnection('concurrency');

        $this->assertSame(self::TEST_DATABASE, DB::selectOne('select database() as name')->name);
        $this->assertStringContainsString('MariaDB', DB::selectOne('select version() as v')->v);
        $this->assertSame(
            0,
            DB::table('information_schema.tables')
                ->where('table_schema', self::TEST_DATABASE)
                ->whereIn('table_name', ['inventory_items', 'reorder_requests', 'stock_transactions', 'workflow_events', 'inventory_alerts'])
                ->where('engine', '!=', 'InnoDB')
                ->count(),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->previousConnection)) {
            foreach ($this->createdItemIds as $itemId) {
                $this->cleanUpItem($itemId);
            }
            DB::setDefaultConnection($this->previousConnection);
            DB::purge('concurrency');
        }

        parent::tearDown();
    }

    public function test_two_concurrent_low_stock_issues_create_one_reorder_recommendation(): void
    {
        for ($round = 1; $round <= 3; $round++) {
            $itemId = $this->createItem(onHand: 15, threshold: 10);

            [$first, $second] = $this->runConcurrently($itemId, 'issue', 6, 6);

            $this->assertTrue($first['ok'], json_encode($first));
            $this->assertTrue($second['ok'], json_encode($second));
            $this->assertGenuinelyConcurrent($first, $second);

            $decisions = [$first['response']['reorder_recommendation'], $second['response']['reorder_recommendation']];
            sort($decisions);
            $this->assertSame(['created', 'suppressed_active_request'], $decisions, "round $round");

            $this->assertSame(3.0, (float) DB::table('inventory_items')->where('id', $itemId)->value('on_hand_quantity'));
            $this->assertSame(1, DB::table('inventory_alerts')->where('item_id', $itemId)->count());
            $this->assertSame(1, DB::table('reorder_requests')->where('item_id', $itemId)->count());
            $this->assertSame(1, DB::table('reorder_requests')->where('item_id', $itemId)->where('status', 'pending_review')->count());

            $transactions = DB::table('stock_transactions')->where('item_id', $itemId)->orderBy('id')->get();
            $this->assertCount(3, $transactions);
            $this->assertSame(['initial', 'issue', 'issue'], $transactions->pluck('transaction_type')->all());
            $this->assertSame([15.0, 9.0, 3.0], $transactions->map(fn ($t) => (float) $t->resulting_quantity)->all());
            $this->assertSame(-12.0, (float) $transactions->where('transaction_type', 'issue')->sum('quantity_delta'));

            $events = $this->eventCounts($itemId);
            $this->assertSame(1, $events['alert.opened'] ?? 0);
            $this->assertSame(1, $events['reorder.recommended'] ?? 0);
            $this->assertSame(2, $events['stock.transaction_recorded'] ?? 0);
        }
    }

    public function test_concurrent_failure_rolls_back_without_side_effects(): void
    {
        $itemId = $this->createItem(onHand: 10, threshold: 5);

        [$first, $second] = $this->runConcurrently($itemId, 'issue', 8, 8);

        $this->assertSame(1, (int) $first['ok'] + (int) $second['ok'], 'Exactly one request must succeed.');
        $failed = $first['ok'] ? $second : $first;
        $this->assertSame(ValidationException::class, $failed['error_class']);
        // A failing request skips the hold, so the loser only waits for one hold period.
        $this->assertGenuinelyConcurrent($first, $second, 0.8);

        $this->assertSame(2.0, (float) DB::table('inventory_items')->where('id', $itemId)->value('on_hand_quantity'));
        $transactions = DB::table('stock_transactions')->where('item_id', $itemId)->orderBy('id')->get();
        $this->assertSame(['initial', 'issue'], $transactions->pluck('transaction_type')->all());
        $this->assertSame(1, DB::table('inventory_alerts')->where('item_id', $itemId)->count());
        $this->assertSame(1, DB::table('reorder_requests')->where('item_id', $itemId)->count());

        $events = $this->eventCounts($itemId);
        $this->assertSame(1, $events['stock.transaction_recorded'] ?? 0);
        $this->assertSame(1, $events['reorder.recommended'] ?? 0);
        $this->assertSame(1, $events['alert.opened'] ?? 0);
    }

    private function createItem(float $onHand, float $threshold): int
    {
        $now = now();
        $itemId = DB::table('inventory_items')->insertGetId([
            'sku' => 'CONC-'.bin2hex(random_bytes(6)),
            'name' => 'Concurrency test item',
            'unit' => 'pcs',
            'on_hand_quantity' => $onHand,
            'reorder_threshold' => $threshold,
            'suggested_reorder_quantity' => 20,
            'is_approved' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->createdItemIds[] = $itemId;
        DB::table('stock_transactions')->insert([
            'item_id' => $itemId,
            'transaction_type' => 'initial',
            'quantity_delta' => $onHand,
            'resulting_quantity' => $onHand,
            'reason' => 'Concurrency test setup',
            'actor_label' => 'Concurrency test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $itemId;
    }

    /** @return array{0: array, 1: array} */
    private function runConcurrently(int $itemId, string $type, float $firstQuantity, float $secondQuantity): array
    {
        $startAt = microtime(true) + 3.0;
        $processes = [];
        foreach ([$firstQuantity, $secondQuantity] as $quantity) {
            $processes[] = $this->startWorker($itemId, $type, $quantity, $startAt);
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $decoded = json_decode((string) $stdout, true);
            $this->assertIsArray($decoded, "Worker produced no JSON. stdout=[$stdout] stderr=[$stderr]");
            $results[] = $decoded;
        }

        return $results;
    }

    private function startWorker(int $itemId, string $type, float $quantity, float $startAt): array
    {
        $command = [
            PHP_BINARY,
            base_path('tests/Support/concurrency_worker.php'),
            (string) $itemId,
            $type,
            (string) $quantity,
            sprintf('%.6F', $startAt),
            (string) self::HOLD_MS,
        ];
        $environment = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => self::TEST_DATABASE,
            'DB_URL' => '',
            'LOG_AUTOMATION_PATH' => $this->automationLogPath,
        ]);
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    private function assertGenuinelyConcurrent(array $first, array $second, float $holdMultiplier = 1.8): void
    {
        $this->assertNotSame($first['connection_id'], $second['connection_id']);
        $this->assertLessThan(
            min($first['finished_at'], $second['finished_at']),
            max($first['started_at'], $second['started_at']),
            'Both requests must have started before either finished.',
        );
        $longest = max($first['finished_at'] - $first['started_at'], $second['finished_at'] - $second['started_at']);
        $this->assertGreaterThan(
            self::HOLD_MS * $holdMultiplier / 1000,
            $longest,
            'The second request should have waited for the first request to release the row lock.',
        );
    }

    private function eventCounts(int $itemId): array
    {
        $ids = $this->subjectIds($itemId);
        $counts = [];
        foreach (DB::table('workflow_events')->get(['subject_type', 'subject_id', 'event_type']) as $event) {
            if (in_array($event->subject_id, $ids[$event->subject_type] ?? [], true)) {
                $counts[$event->event_type] = ($counts[$event->event_type] ?? 0) + 1;
            }
        }

        return $counts;
    }

    private function subjectIds(int $itemId): array
    {
        return [
            'inventory_item' => [$itemId],
            'stock_transaction' => DB::table('stock_transactions')->where('item_id', $itemId)->pluck('id')->all(),
            'inventory_alert' => DB::table('inventory_alerts')->where('item_id', $itemId)->pluck('id')->all(),
            'reorder_request' => DB::table('reorder_requests')->where('item_id', $itemId)->pluck('id')->all(),
        ];
    }

    private function cleanUpItem(int $itemId): void
    {
        foreach ($this->subjectIds($itemId) as $type => $ids) {
            DB::table('workflow_events')->where('subject_type', $type)->whereIn('subject_id', $ids)->delete();
        }
        DB::table('reorder_requests')->where('item_id', $itemId)->delete();
        DB::table('inventory_alerts')->where('item_id', $itemId)->delete();
        DB::table('stock_transactions')->where('item_id', $itemId)->delete();
        DB::table('inventory_items')->where('id', $itemId)->delete();
    }
}
