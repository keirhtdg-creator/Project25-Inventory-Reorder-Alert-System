<?php

namespace Tests\Feature;

use App\Services\StockRiskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AgingEscalationTest extends TestCase
{
    use RefreshDatabase;

    private const MANILA = 'Asia/Manila';

    protected function setUp(): void
    {
        parent::setUp();
        config(['inventory.supervisor_owner' => 'supervisor@example.test', 'inventory.aging_days' => 7]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_reorder_aged_six_days_does_not_escalate(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$requestId] = $this->reorderChangedAt($changedAt);

        $this->travelTo($changedAt->addDays(6)->addHours(23)->addMinutes(59));

        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());
        $this->assertNoEscalation($requestId);
    }

    public function test_reorder_escalates_at_exactly_seven_manila_calendar_days(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$requestId] = $this->reorderChangedAt($changedAt);

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:29:59', self::MANILA));
        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());

        $this->travelTo(CarbonImmutable::parse('2026-10-08 09:30:00', self::MANILA));
        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());

        $this->assertSame(1, DB::table('escalation_events')->where('reorder_request_id', $requestId)->count());
    }

    public function test_reorder_older_than_seven_days_escalates(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$requestId] = $this->reorderChangedAt($changedAt);

        $this->travelTo($changedAt->addDays(12));

        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());
        $this->assertSame(1, DB::table('escalation_events')->where('reorder_request_id', $requestId)->count());
    }

    public function test_seven_days_across_a_manila_month_boundary_uses_manila_wall_clock(): void
    {
        // 2026-10-31 23:30 Manila is 15:30 UTC; seven days later is 2026-11-07 23:30 Manila.
        $changedAt = CarbonImmutable::parse('2026-10-31 23:30:00', self::MANILA);
        [$requestId] = $this->reorderChangedAt($changedAt);

        $this->travelTo(CarbonImmutable::parse('2026-11-07 23:29:00', self::MANILA));
        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());

        $this->travelTo(CarbonImmutable::parse('2026-11-07 23:30:00', self::MANILA));
        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());
        $this->assertSame(1, DB::table('escalation_events')->where('reorder_request_id', $requestId)->count());
    }

    public function test_status_change_resets_the_aging_period(): void
    {
        $createdAt = CarbonImmutable::parse('2026-10-01 09:00:00', self::MANILA);
        $this->travelTo($createdAt);
        $itemId = $this->createItem(2, 5, 4);
        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');

        $approvedAt = $createdAt->addDays(6);
        $this->travelTo($approvedAt);
        $this->postJson("/api/v1/reorder-requests/{$requestId}/review", ['decision' => 'approve', 'reviewer' => 'Supervisor'])
            ->assertSuccessful();

        // Seven days after creation, but only one day after the approval.
        $this->travelTo($createdAt->addDays(7));
        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());

        $this->travelTo($approvedAt->addDays(7)->subSecond());
        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());

        $this->travelTo($approvedAt->addDays(7));
        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());
    }

    public function test_normal_escalation_with_supervisor_owner_configured(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$requestId, $itemId] = $this->reorderChangedAt($changedAt);
        $this->travelTo($changedAt->addDays(8));

        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());

        $this->assertDatabaseHas('escalation_events', ['reorder_request_id' => $requestId, 'escalation_level' => 1]);
        $this->assertDatabaseHas('automation_runs', [
            'idempotency_key' => "aging:reorder:{$requestId}:level:1",
            'automation_type' => 'aging_escalation',
            'status' => 'completed',
            'last_error' => null,
        ]);
        $this->assertDatabaseHas('manual_actions', [
            'reorder_request_id' => $requestId,
            'assigned_role' => 'supervisor',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('workflow_events', [
            'subject_type' => 'reorder_request',
            'subject_id' => $requestId,
            'event_type' => 'reorder.aging_escalated',
        ]);
        $this->assertDatabaseMissing('workflow_events', ['event_type' => 'automation.permanent_failure_routed']);
        $this->assertSame('pending_review', DB::table('reorder_requests')->where('id', $requestId)->value('status'));

        $entries = array_values(array_filter(
            $this->automationLogEntries(),
            fn (array $entry): bool => $entry['event'] === 'reorder.aging_escalated',
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('escalated', $entries[0]['outcome']);
        $this->assertSame($requestId, $entries[0]['reorder_request_id']);
        $this->assertSame($itemId, $entries[0]['inventory_item_id']);
        $this->assertArrayNotHasKey('error_code', $entries[0]);
        $this->assertIsInt($entries[0]['duration_ms']);
    }

    public function test_missing_supervisor_owner_routes_to_manual_action(): void
    {
        config(['inventory.supervisor_owner' => null]);
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$requestId] = $this->reorderChangedAt($changedAt);
        $this->travelTo($changedAt->addDays(7));

        $this->assertSame(1, app(StockRiskAutomationService::class)->processAging());

        $this->assertDatabaseHas('automation_runs', [
            'idempotency_key' => "aging:reorder:{$requestId}:level:1",
            'status' => 'permanent_failure',
        ]);
        $this->assertDatabaseHas('manual_actions', ['reorder_request_id' => $requestId, 'status' => 'open']);
        $this->assertDatabaseHas('workflow_events', [
            'subject_id' => $requestId,
            'event_type' => 'automation.permanent_failure_routed',
        ]);

        $entries = array_values(array_filter(
            $this->automationLogEntries(),
            fn (array $entry): bool => $entry['event'] === 'automation.aging_permanent_failure_manual_action_created',
        ));
        $this->assertCount(1, $entries);
        $this->assertSame('SUPERVISOR_OWNER_NOT_CONFIGURED', $entries[0]['error_code']);
    }

    public function test_repeated_processing_creates_no_duplicates_and_leaves_other_records_unchanged(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$agedId] = $this->reorderChangedAt($changedAt);
        $this->travelTo($changedAt->addDays(3));
        $freshItem = $this->createItem(1, 5, 4);
        $freshId = (int) DB::table('reorder_requests')->where('item_id', $freshItem)->value('id');
        $this->travelTo($changedAt->addDays(8));

        $service = app(StockRiskAutomationService::class);
        $this->assertSame(1, $service->processAging());
        $this->assertSame(0, $service->processAging());
        $this->travelTo($changedAt->addDays(9));
        $this->assertSame(0, $service->processAging());

        $this->assertSame(1, DB::table('escalation_events')->where('reorder_request_id', $agedId)->count());
        $this->assertSame(1, DB::table('manual_actions')->where('reorder_request_id', $agedId)->count());
        $this->assertSame(1, DB::table('workflow_events')->where('subject_id', $agedId)->where('event_type', 'reorder.aging_escalated')->count());
        $this->assertSame(1, DB::table('automation_runs')->where('idempotency_key', "aging:reorder:{$agedId}:level:1")->count());
        $this->assertNoEscalation($freshId);
    }

    public function test_closed_and_rejected_reorders_are_not_escalated(): void
    {
        $changedAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$closedId] = $this->reorderChangedAt($changedAt);
        DB::table('reorder_requests')->where('id', $closedId)->update(['status' => 'closed']);
        [$rejectedId] = $this->reorderChangedAt($changedAt);
        DB::table('reorder_requests')->where('id', $rejectedId)->update(['status' => 'rejected']);
        $this->travelTo($changedAt->addDays(30));

        $this->assertSame(0, app(StockRiskAutomationService::class)->processAging());
        $this->assertDatabaseCount('escalation_events', 0);
    }

    public function test_artisan_command_processes_only_eligible_records_and_is_idempotent(): void
    {
        $oldAt = CarbonImmutable::parse('2026-10-01 09:30:00', self::MANILA);
        [$agedId] = $this->reorderChangedAt($oldAt);
        $this->travelTo($oldAt->addDays(4));
        $freshItem = $this->createItem(1, 5, 4);
        $freshId = (int) DB::table('reorder_requests')->where('item_id', $freshItem)->value('id');
        $freshBefore = (array) DB::table('reorder_requests')->where('id', $freshId)->first();
        $this->travelTo($oldAt->addDays(7));

        $this->artisan('inventory:process-aging')
            ->expectsOutput('Processed 1 aging escalation(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('escalation_events', ['reorder_request_id' => $agedId, 'escalation_level' => 1]);
        $this->assertDatabaseHas('workflow_events', ['subject_id' => $agedId, 'event_type' => 'reorder.aging_escalated']);
        $this->assertNoEscalation($freshId);
        $this->assertSame($freshBefore, (array) DB::table('reorder_requests')->where('id', $freshId)->first());

        $this->artisan('inventory:process-aging')
            ->expectsOutput('Processed 0 aging escalation(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('escalation_events', 1);
        $this->assertDatabaseCount('manual_actions', 1);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'reorder.aging_escalated')->count());

        $completed = array_values(array_filter(
            $this->automationLogEntries(),
            fn (array $entry): bool => $entry['event'] === 'reorder.aging_scan_completed',
        ));
        $this->assertCount(2, $completed);
        $this->assertSame([1, 0], array_column($completed, 'escalated_count'));
        $this->assertNotSame($completed[0]['correlation_id'], $completed[1]['correlation_id']);
        $this->assertIsInt($completed[0]['duration_ms']);
    }

    private function assertNoEscalation(int $requestId): void
    {
        $this->assertSame(0, DB::table('escalation_events')->where('reorder_request_id', $requestId)->count());
        $this->assertSame(0, DB::table('manual_actions')->where('reorder_request_id', $requestId)->count());
        $this->assertSame(0, DB::table('workflow_events')->where('subject_id', $requestId)
            ->whereIn('event_type', ['reorder.aging_escalated', 'automation.permanent_failure_routed'])->count());
    }

    /** @return array{0: int, 1: int} reorder request id and item id */
    private function reorderChangedAt(CarbonImmutable $changedAt): array
    {
        $itemId = $this->createItem(2, 5, 4);
        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');
        // Timestamps are stored in UTC (app timezone); convert so a Manila instant is not stored as local wall time.
        DB::table('reorder_requests')->where('id', $requestId)->update(['status_changed_at' => $changedAt->utc()]);

        return [$requestId, $itemId];
    }

    private function createItem(float $quantity, float $threshold, float $suggested): int
    {
        $response = $this->postJson('/api/v1/items', [
            'sku' => 'AGE-'.fake()->unique()->numerify('#####'),
            'name' => 'Aging test supply',
            'unit' => 'piece',
            'on_hand_quantity' => $quantity,
            'reorder_threshold' => $threshold,
            'suggested_reorder_quantity' => $suggested,
        ])->assertCreated();

        return (int) $response->json('data.id');
    }
}
