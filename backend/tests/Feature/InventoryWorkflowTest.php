<?php

namespace Tests\Feature;

use App\Services\StockRiskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_low_stock_creates_one_alert_and_one_reorder_recommendation(): void
    {
        $itemId = $this->createItem(10, 5, 6);

        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'issue',
            'quantity' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.threshold_alert', 'opened')
            ->assertJsonPath('data.reorder_recommendation', 'created');

        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'issue',
            'quantity' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.threshold_alert', 'retained')
            ->assertJsonPath('data.reorder_recommendation', 'suppressed_active_request');

        $this->assertDatabaseCount('inventory_alerts', 1);
        $this->assertDatabaseCount('reorder_requests', 1);
        $this->assertDatabaseHas('inventory_items', ['id' => $itemId, 'on_hand_quantity' => '4.000']);
    }

    public function test_repeated_low_stock_transactions_do_not_create_duplicate_reorder_events(): void
    {
        $itemId = $this->createItem(10, 5, 6);

        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'issue',
            'quantity' => 5,
        ])->assertCreated()
            ->assertJsonPath('data.reorder_recommendation', 'created');

        $recommendationEventCount = DB::table('workflow_events')
            ->where('event_type', 'reorder.recommended')
            ->count();

        foreach ([1, 0.5] as $quantity) {
            $this->postJson("/api/v1/items/{$itemId}/transactions", [
                'type' => 'issue',
                'quantity' => $quantity,
            ])->assertCreated()
                ->assertJsonPath('data.reorder_recommendation', 'suppressed_active_request');
        }

        $this->assertSame(1, DB::table('reorder_requests')->where('item_id', $itemId)->count());
        $this->assertSame(
            $recommendationEventCount,
            DB::table('workflow_events')->where('event_type', 'reorder.recommended')->count(),
        );
        $this->assertDatabaseHas('inventory_items', [
            'id' => $itemId,
            'on_hand_quantity' => '3.500',
        ]);
        $this->assertDatabaseCount('stock_transactions', 4);
    }

    public function test_normal_workflow_reaches_closed_after_receipt(): void
    {
        $itemId = $this->createItem(10, 5, 6);
        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'issue',
            'quantity' => 6,
        ])->assertCreated();
        $requestId = (int) DB::table('reorder_requests')->value('id');

        $this->postJson("/api/v1/reorder-requests/{$requestId}/review", [
            'decision' => 'approve',
            'note' => 'Approved for reorder.',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->patchJson("/api/v1/reorder-requests/{$requestId}/procurement-status", [
            'status' => 'ordered',
        ])->assertOk()->assertJsonPath('data.status', 'ordered');
        $this->postJson("/api/v1/reorder-requests/{$requestId}/receipts", [
            'quantity' => 6,
        ])->assertOk()->assertJsonPath('data.status', 'received');
        $this->postJson("/api/v1/reorder-requests/{$requestId}/close", [
            'resolution_note' => 'All items received.',
        ])->assertOk()->assertJsonPath('data.status', 'closed');

        $this->assertDatabaseHas('reorder_requests', ['id' => $requestId, 'status' => 'closed']);
        $this->assertDatabaseHas('inventory_items', ['id' => $itemId, 'on_hand_quantity' => '10.000']);
    }

    public function test_invalid_transaction_does_not_change_stock(): void
    {
        $itemId = $this->createItem(10, 5, 6);

        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'issue',
            'quantity' => -2,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId, 'on_hand_quantity' => '10.000']);
        $this->assertDatabaseCount('stock_transactions', 1);
    }

    public function test_items_api_returns_the_documented_data_envelope_and_correlation_id(): void
    {
        $this->createItem(10, 5, 6);

        $this->withHeader('X-Correlation-ID', 'phase1-contract')
            ->getJson('/api/v1/items')
            ->assertOk()
            ->assertHeader('X-Correlation-ID', 'phase1-contract')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'sku',
                        'name',
                        'unit',
                        'on_hand_quantity',
                        'reorder_threshold',
                        'suggested_reorder_quantity',
                        'below_threshold',
                    ],
                ],
            ])
            ->assertJsonMissingPath('meta');
    }

    public function test_inventory_validation_errors_are_json_and_leave_stock_unchanged(): void
    {
        $itemId = $this->createItem(10, 5, 6);

        foreach ([
            ['type' => 'issue', 'quantity' => 0],
            ['type' => 'issue', 'quantity' => -1],
            ['type' => 'receipt', 'quantity' => -1],
            ['type' => 'adjustment', 'quantity' => 0],
            ['type' => 'issue', 'quantity' => 1.2345],
            ['type' => 'issue', 'quantity' => 11],
        ] as $invalidTransaction) {
            $response = $this->postJson("/api/v1/items/{$itemId}/transactions", $invalidTransaction);

            $response->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonStructure(['message', 'errors' => ['quantity']]);
        }

        $this->postJson("/api/v1/items/{$itemId}/transactions", [
            'type' => 'transfer',
            'quantity' => 1,
        ])->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['type']]);

        $this->assertDatabaseHas('inventory_items', ['id' => $itemId, 'on_hand_quantity' => '10.000']);
        $this->assertDatabaseCount('stock_transactions', 1);
        $this->assertDatabaseCount('inventory_alerts', 0);
        $this->assertDatabaseCount('reorder_requests', 0);
    }

    public function test_inventory_creation_rejects_values_outside_the_database_precision(): void
    {
        $this->postJson('/api/v1/items', [
            'sku' => 'TEST-'.fake()->unique()->numerify('###'),
            'name' => 'Invalid threshold supply',
            'unit' => 'piece',
            'on_hand_quantity' => 10,
            'reorder_threshold' => -1,
            'suggested_reorder_quantity' => 6,
        ])->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['reorder_threshold']]);

        $this->postJson('/api/v1/items', [
            'sku' => 'TEST-'.fake()->unique()->numerify('###'),
            'name' => 'Excess precision supply',
            'unit' => 'piece',
            'on_hand_quantity' => 10.1234,
            'reorder_threshold' => 5,
            'suggested_reorder_quantity' => 6,
        ])->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['on_hand_quantity']]);

        $this->assertDatabaseCount('inventory_items', 0);
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_invalid_reorder_transitions_return_conflict_without_mutation(): void
    {
        $itemId = $this->createItem(2, 5, 4);
        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');
        $initialEventCount = DB::table('workflow_events')->count();

        $this->withHeader('X-Correlation-ID', 'phase1-conflict')
            ->patchJson("/api/v1/reorder-requests/{$requestId}/procurement-status", [
                'status' => 'ordered',
            ])->assertStatus(409)
            ->assertHeader('X-Correlation-ID', 'phase1-conflict')
            ->assertJsonPath('message', 'Only approved requests can be marked ordered.');

        $this->assertDatabaseHas('reorder_requests', [
            'id' => $requestId,
            'status' => 'pending_review',
        ]);
        $this->assertSame($initialEventCount, DB::table('workflow_events')->count());

        $this->postJson("/api/v1/reorder-requests/{$requestId}/review", [
            'decision' => 'approve',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $eventCountAfterApproval = DB::table('workflow_events')->count();

        $this->postJson("/api/v1/reorder-requests/{$requestId}/review", [
            'decision' => 'reject',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Only pending recommendations can be reviewed.');

        $this->assertDatabaseHas('reorder_requests', [
            'id' => $requestId,
            'status' => 'approved',
        ]);
        $this->assertSame($eventCountAfterApproval, DB::table('workflow_events')->count());
    }

    public function test_missing_inventory_resource_returns_json_not_found_response(): void
    {
        $this->withHeader('X-Correlation-ID', 'phase1-not-found')
            ->getJson('/api/v1/items/999999')
            ->assertNotFound()
            ->assertHeader('X-Correlation-ID', 'phase1-not-found')
            ->assertJsonPath('message', 'Inventory item not found.');
    }

    public function test_weekly_summary_recovers_from_one_transient_failure_without_duplicates(): void
    {
        $this->createItem(2, 5, 4);
        $service = app(StockRiskAutomationService::class);
        $reference = CarbonImmutable::now('Asia/Manila')->addWeek();

        $summary = $service->generateWeeklySummary($reference, simulateTransientFailure: true);
        $repeatedSummary = $service->generateWeeklySummary($reference);

        $this->assertSame(1, (int) $summary->risk_item_count);
        $this->assertSame($summary->id, $repeatedSummary->id);
        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseCount('weekly_stock_summary_items', 1);
        $this->assertDatabaseHas('automation_runs', [
            'idempotency_key' => 'weekly-stock-risk:'.$reference->startOfWeek(CarbonImmutable::MONDAY)->subWeek()->toDateString(),
            'attempt_count' => 2,
            'status' => 'completed',
        ]);
        $failures = array_values(array_filter(
            $this->automationLogEntries(),
            fn (array $entry): bool => $entry['event'] === 'weekly_stock_summary.attempt_failed',
        ));
        $this->assertCount(1, $failures);
        $this->assertSame('warning', $failures[0]['level']);
        $this->assertSame(1, $failures[0]['attempt']);
        $this->assertSame('retry_scheduled', $failures[0]['outcome']);
    }

    public function test_aged_reorder_with_missing_supervisor_owner_creates_manual_action_once(): void
    {
        config(['inventory.supervisor_owner' => null]);
        $itemId = $this->createItem(2, 5, 4);
        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');
        DB::table('reorder_requests')->where('id', $requestId)->update([
            'status_changed_at' => now()->subDays(8),
        ]);

        $service = app(StockRiskAutomationService::class);
        $this->assertSame(1, $service->processAging());
        $this->assertSame(0, $service->processAging());

        $this->assertDatabaseCount('escalation_events', 1);
        $this->assertDatabaseCount('manual_actions', 1);
        $this->assertDatabaseHas('automation_runs', [
            'idempotency_key' => "aging:reorder:{$requestId}:level:1",
            'status' => 'permanent_failure',
        ]);
        $this->assertDatabaseHas('manual_actions', [
            'reorder_request_id' => $requestId,
            'assigned_role' => 'supervisor',
            'status' => 'open',
        ]);
        $routed = array_values(array_filter(
            $this->automationLogEntries(),
            fn (array $entry): bool => $entry['event'] === 'automation.aging_permanent_failure_manual_action_created',
        ));
        $this->assertCount(1, $routed);
        $this->assertSame('error', $routed[0]['level']);
        $this->assertSame('permanent_failure_routed', $routed[0]['outcome']);
    }

    public function test_manual_action_can_be_resolved_by_human(): void
    {
        $actionId = DB::table('manual_actions')->insertGetId([
            'assigned_role' => 'supervisor',
            'reason' => 'Review required.',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson("/api/v1/manual-actions/{$actionId}/resolve", [
            'resolution_note' => 'Supervisor assigned an owner and reviewed the item.',
        ])->assertOk()->assertJsonPath('data.status', 'resolved');
    }

    private function createItem(float $quantity, float $threshold, float $suggested): int
    {
        $response = $this->postJson('/api/v1/items', [
            'sku' => 'TEST-'.fake()->unique()->numerify('###'),
            'name' => 'Test supply',
            'unit' => 'piece',
            'on_hand_quantity' => $quantity,
            'reorder_threshold' => $threshold,
            'suggested_reorder_quantity' => $suggested,
        ])->assertCreated();

        return (int) $response->json('data.id');
    }
}
