<?php

namespace Tests\Feature;

use App\Logging\AutomationLog;
use App\Services\InventoryWorkflowService;
use App\Services\StockRiskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * These tests read the real automation log file written by the "automation" channel;
 * none of them mock the logger.
 */
class StructuredLoggingTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function test_successful_event_is_one_valid_json_object_with_required_fields(): void
    {
        $itemId = $this->createLowStockItem();

        $entry = $this->entry('inventory.threshold_evaluated');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $entry['timestamp']);
        $this->assertSame('info', $entry['level']);
        $this->assertSame($itemId, $entry['inventory_item_id']);
        $this->assertSame('created', $entry['outcome']);
        $this->assertSame('opened', $entry['alert_decision']);
        $this->assertIsInt($entry['reorder_request_id']);
        $this->assertIsInt($entry['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $entry['duration_ms']);
        $this->assertNotEmpty($entry['correlation_id']);
    }

    public function test_http_correlation_id_is_preserved_in_logs_and_workflow_events(): void
    {
        $this->withHeader('X-Correlation-ID', 'phase3b-http-id')->postJson('/api/v1/items', [
            'sku' => 'LOG-001',
            'name' => 'Logging supply',
            'unit' => 'piece',
            'on_hand_quantity' => 1,
            'reorder_threshold' => 5,
            'suggested_reorder_quantity' => 4,
        ])->assertCreated()->assertHeader('X-Correlation-ID', 'phase3b-http-id');

        $this->assertSame('phase3b-http-id', $this->entry('inventory.threshold_evaluated')['correlation_id']);
        $this->assertSame(
            'phase3b-http-id',
            DB::table('workflow_events')->where('event_type', 'reorder.recommended')->value('correlation_id'),
        );
    }

    public function test_each_automation_execution_gets_its_own_generated_correlation_id(): void
    {
        config(['inventory.supervisor_owner' => 'Purchasing supervisor']);
        $itemId = $this->createLowStockItem();
        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');
        DB::table('reorder_requests')->where('id', $requestId)->update(['status_changed_at' => now()->subDays(8)]);
        $service = app(StockRiskAutomationService::class);

        $this->assertSame(1, $service->processAging());
        $this->assertSame(0, $service->processAging());

        $escalated = $this->entry('reorder.aging_escalated');
        $scans = $this->entries('reorder.aging_scan_completed');
        $this->assertCount(2, $scans);

        $this->assertMatchesRegularExpression(self::UUID, $escalated['correlation_id']);
        $this->assertSame($escalated['correlation_id'], $scans[0]['correlation_id']);
        $this->assertNotSame($scans[0]['correlation_id'], $scans[1]['correlation_id']);
        $this->assertSame(1, $scans[0]['escalated_count']);
        $this->assertSame(0, $scans[1]['escalated_count']);
        $this->assertSame($requestId, $escalated['reorder_request_id']);
        $this->assertSame($itemId, $escalated['inventory_item_id']);
        $this->assertSame('escalated', $escalated['outcome']);
        $this->assertSame("aging:reorder:{$requestId}:level:1", $escalated['idempotency_key']);
        $this->assertArrayHasKey('automation_run_id', $escalated);
        foreach ([$escalated, ...$scans] as $entry) {
            $this->assertIsInt($entry['duration_ms']);
        }
        $this->assertSame(
            $escalated['correlation_id'],
            DB::table('workflow_events')->where('event_type', 'reorder.aging_escalated')->value('correlation_id'),
        );
    }

    public function test_failed_attempt_and_recovery_are_logged_as_json_with_error_details(): void
    {
        $this->createLowStockItem();
        $reference = CarbonImmutable::now('Asia/Manila')->addWeek();

        app(StockRiskAutomationService::class)->generateWeeklySummary($reference, simulateTransientFailure: true);

        $failed = $this->entry('weekly_stock_summary.attempt_failed');
        $this->assertSame('warning', $failed['level']);
        $this->assertSame(1, $failed['attempt']);
        $this->assertSame('retry_scheduled', $failed['outcome']);
        $this->assertSame('SIMULATED_TRANSIENT_FAILURE', $failed['error_code']);
        $this->assertSame('A simulated transient database failure was injected.', $failed['error']);
        $this->assertIsInt($failed['duration_ms']);

        $recovered = $this->entry('weekly_stock_summary.recovered');
        $this->assertSame(2, $recovered['attempt']);
        $this->assertSame('completed', $recovered['outcome']);
        $this->assertSame($failed['correlation_id'], $recovered['correlation_id']);
        $this->assertSame($failed['idempotency_key'], $recovered['idempotency_key']);
        $this->assertIsInt($recovered['automation_run_id']);
    }

    public function test_permanent_failure_is_logged_with_sanitized_error_and_manual_action(): void
    {
        $this->createLowStockItem();
        $failingWorkflow = new class(app(AutomationLog::class)) extends InventoryWorkflowService
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
                    parent::recordEvent($subjectType, $subjectId, $eventType, $previousState, $currentState, $details, $actorLabel);

                    return;
                }
                throw new RuntimeException('boom password=hunter2 mysql://root:s3cret@127.0.0.1/db');
            }
        };
        $service = new StockRiskAutomationService($failingWorkflow, app(AutomationLog::class));

        try {
            $service->generateWeeklySummary(CarbonImmutable::now('Asia/Manila')->addWeek());
            $this->fail('The permanent failure should be rethrown.');
        } catch (RuntimeException) {
        }

        $failed = $this->entry('weekly_stock_summary.attempt_failed');
        $this->assertSame('error', $failed['level']);
        $this->assertSame('failed', $failed['outcome']);
        $this->assertSame('AUTOMATION_FAILURE', $failed['error_code']);
        $routed = $this->entry('weekly_stock_summary.manual_action_created');
        $this->assertSame('permanent_failure_routed', $routed['outcome']);
        $this->assertSame($failed['correlation_id'], $routed['correlation_id']);
        $this->assertIsInt($routed['duration_ms']);
        $this->assertDatabaseHas('manual_actions', ['status' => 'open', 'automation_run_id' => $routed['automation_run_id']]);

        $raw = file_get_contents($this->automationLogPath);
        $this->assertStringNotContainsString('hunter2', $raw);
        $this->assertStringNotContainsString('s3cret', $raw);
    }

    public function test_sensitive_values_are_redacted_from_written_log_lines(): void
    {
        app(AutomationLog::class)->write('error', 'weekly_stock_summary.attempt_failed', [
            'password' => 'hunter2',
            'api_token' => 'tok-12345',
            'DB_PASSWORD' => 'dbsecret',
            'nested' => ['authorization' => 'Bearer abc.def.ghi'],
            'error' => "SQLSTATE failed password=hunter2 Bearer abc.def.ghi mysql://root:s3cret@127.0.0.1/db\nsecond line",
        ]);

        $raw = file_get_contents($this->automationLogPath);
        foreach (['hunter2', 'tok-12345', 'dbsecret', 'abc.def.ghi', 's3cret'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $configuredPassword = (string) config('database.connections.mysql.password');
        if ($configuredPassword !== '') {
            $this->assertStringNotContainsString($configuredPassword, $raw);
        }
        $entry = $this->entry('weekly_stock_summary.attempt_failed');
        $this->assertSame('[REDACTED]', $entry['password']);
        $this->assertStringContainsString('SQLSTATE failed', $entry['error']);
    }

    public function test_every_line_is_an_independent_single_line_json_object(): void
    {
        $this->createLowStockItem();
        app(StockRiskAutomationService::class)->generateWeeklySummary(CarbonImmutable::now('Asia/Manila')->addWeek());
        app(AutomationLog::class)->write('warning', 'multi.line.example', ['error' => "line one\nline two\r\nline three"]);

        $lines = file($this->automationLogPath, FILE_IGNORE_NEW_LINES);
        $this->assertGreaterThanOrEqual(3, count($lines));
        foreach ($lines as $number => $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Line '.($number + 1).' is not a JSON object.');
            foreach (['timestamp', 'level', 'event', 'correlation_id'] as $field) {
                $this->assertArrayHasKey($field, $decoded);
            }
        }
        $this->assertSame(count($lines), substr_count(file_get_contents($this->automationLogPath), "\n"));
        $this->assertSame("line one\nline two\r\nline three", $this->entry('multi.line.example')['error']);
    }

    private function createLowStockItem(): int
    {
        return app(InventoryWorkflowService::class)->createItem([
            'sku' => 'LOG-'.fake()->unique()->numerify('####'),
            'name' => 'Logging supply',
            'unit' => 'piece',
            'on_hand_quantity' => 2,
            'reorder_threshold' => 5,
            'suggested_reorder_quantity' => 4,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function entries(string $event): array
    {
        return array_values(array_filter($this->automationLogEntries(), fn (array $entry): bool => $entry['event'] === $event));
    }

    private function entry(string $event): array
    {
        $matches = $this->entries($event);
        $this->assertNotEmpty($matches, "No log entry for event {$event}.");

        return $matches[0];
    }
}
