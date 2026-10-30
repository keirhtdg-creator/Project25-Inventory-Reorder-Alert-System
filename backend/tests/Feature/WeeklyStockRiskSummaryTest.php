<?php

namespace Tests\Feature;

use App\Logging\AutomationLog;
use App\Services\InventoryWorkflowService;
use App\Services\StockRiskAutomationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class WeeklyStockRiskSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const MANILA = 'Asia/Manila';

    // Reporting week under test: Monday 2026-10-19 00:00 to Sunday 2026-10-25 23:59:59 Asia/Manila.
    // Sunday 23:59:59 Manila is 15:59:59 UTC on the same date; Monday 00:00 Manila is 16:00:00 UTC.
    private const LAST_INSTANT_UTC = '2026-10-25 15:59:59';

    private const FIRST_INSTANT_AFTER_UTC = '2026-10-25 16:00:00';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_monday_0800_run_selects_the_previous_completed_monday_to_sunday_week(): void
    {
        $summary = app(StockRiskAutomationService::class)
            ->generateWeeklySummary(CarbonImmutable::parse('2026-10-26 08:00:00', self::MANILA));

        $this->assertSame('2026-10-19', $summary->week_start);
        $this->assertSame('2026-10-25', $summary->week_end);
        $this->assertSame(1, (int) CarbonImmutable::parse($summary->week_start)->dayOfWeekIso);
        $this->assertSame(7, (int) CarbonImmutable::parse($summary->week_end)->dayOfWeekIso);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19']);
    }

    public function test_sunday_and_monday_midnight_boundaries_select_the_correct_week(): void
    {
        $service = app(StockRiskAutomationService::class);

        // Still inside the Oct 19-25 week, so the last completed week is Oct 12-18.
        $beforeMidnight = $service->generateWeeklySummary(CarbonImmutable::parse('2026-10-25 23:59:59', self::MANILA));
        $this->assertSame(['2026-10-12', '2026-10-18'], [$beforeMidnight->week_start, $beforeMidnight->week_end]);

        // Monday 00:00:00 Manila: the Oct 19-25 week has completed.
        $atMidnight = $service->generateWeeklySummary(CarbonImmutable::parse('2026-10-26 00:00:00', self::MANILA));
        $this->assertSame(['2026-10-19', '2026-10-25'], [$atMidnight->week_start, $atMidnight->week_end]);

        // The same instant expressed in UTC (Sunday 16:00) gives the same Manila-based week.
        $utcReference = CarbonImmutable::parse('2026-10-25 16:00:00', 'UTC');
        $again = $service->generateWeeklySummary($utcReference);
        $this->assertSame($atMidnight->id, $again->id);
    }

    public function test_transactions_at_and_around_the_period_edges_are_handled_correctly(): void
    {
        $inside = $this->item('EDGE-IN', 5, '2026-10-01 00:00:00');
        $this->transaction($inside, 10, self::LAST_INSTANT_UTC);
        $this->transaction($inside, 2, self::FIRST_INSTANT_AFTER_UTC);

        $carried = $this->item('EDGE-OLD', 5, '2026-09-01 00:00:00');
        $this->transaction($carried, 8, '2026-09-10 00:00:00');
        $this->transaction($carried, 4, '2026-10-22 03:00:00');
        $this->transaction($carried, 20, '2026-10-28 03:00:00');

        $summary = $this->generateForOct26();
        $rows = $this->summaryRows($summary);

        $this->assertEqualsWithDelta(10.0, (float) $rows[$inside]->on_hand_quantity, 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $rows[$carried]->on_hand_quantity, 0.0001);
        $this->assertSame(1, (int) $summary->risk_item_count);
    }

    public function test_multiple_items_totals_and_threshold_rule_are_accurate(): void
    {
        $above = $this->item('RISK-ABOVE', 5, '2026-10-01 00:00:00');
        $this->transaction($above, 5.001, '2026-10-20 01:00:00');
        $equal = $this->item('RISK-EQUAL', 5, '2026-10-01 00:00:00');
        $this->transaction($equal, 5, '2026-10-20 01:00:00');
        $below = $this->item('RISK-BELOW', 5, '2026-10-01 00:00:00');
        $this->transaction($below, 0, '2026-10-20 01:00:00');
        $unapproved = $this->item('RISK-UNAPPROVED', 5, '2026-10-01 00:00:00', approved: false);
        $this->transaction($unapproved, 1, '2026-10-20 01:00:00');
        $noTransactions = $this->item('RISK-NO-TX', 5, '2026-10-01 00:00:00');

        $summary = $this->generateForOct26();
        $rows = $this->summaryRows($summary);

        $this->assertSame([$above, $equal, $below], array_keys($rows));
        $this->assertArrayNotHasKey($unapproved, $rows);
        $this->assertArrayNotHasKey($noTransactions, $rows);
        $this->assertSame(2, (int) $summary->risk_item_count);
        $this->assertSame(3, DB::table('weekly_stock_summary_items')->where('summary_id', $summary->id)->count());
    }

    public function test_items_created_after_the_reporting_period_are_excluded(): void
    {
        $inPeriod = $this->item('LATE-IN', 5, '2026-10-25 15:59:59');
        $this->transaction($inPeriod, 1, '2026-10-25 15:59:59');
        $afterPeriod = $this->item('LATE-OUT', 5, self::FIRST_INSTANT_AFTER_UTC);
        $this->transaction($afterPeriod, 1, self::FIRST_INSTANT_AFTER_UTC);

        $summary = $this->generateForOct26();
        $rows = $this->summaryRows($summary);

        $this->assertSame([$inPeriod], array_keys($rows));
        $this->assertSame(1, (int) $summary->risk_item_count);
    }

    public function test_reorder_status_is_the_current_status_not_the_status_during_the_reporting_week(): void
    {
        $item = $this->item('STATUS-CURRENT', 5, '2026-10-01 00:00:00');
        $this->transaction($item, 1, '2026-10-20 01:00:00');
        // The request was pending_review during the reporting week and became ordered afterwards.
        $this->reorder($item, 'ordered', '2026-10-28 01:00:00');
        $closed = $this->item('STATUS-CLOSED', 5, '2026-10-01 00:00:00');
        $this->transaction($closed, 1, '2026-10-20 01:00:00');
        $this->reorder($closed, 'closed', '2026-10-28 01:00:00');

        $rows = $this->summaryRows($this->generateForOct26());

        $this->assertSame('ordered', $rows[$item]->reorder_status);
        $this->assertNull($rows[$closed]->reorder_status);
    }

    public function test_repeating_generation_returns_the_existing_summary_without_duplicates(): void
    {
        $item = $this->item('DUP-1', 5, '2026-10-01 00:00:00');
        $this->transaction($item, 1, '2026-10-20 01:00:00');

        $first = $this->generateForOct26();
        $second = $this->generateForOct26();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->generated_at, $second->generated_at);
        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseCount('weekly_stock_summary_items', 1);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());
        $generated = array_filter($this->automationLogEntries(), fn (array $e): bool => $e['event'] === 'weekly_stock_summary.generated');
        $this->assertCount(1, $generated);
    }

    public function test_failed_summary_leaves_no_partial_data_blocks_rerun_until_resolved_then_succeeds(): void
    {
        $item = $this->item('FAIL-1', 5, '2026-10-01 00:00:00');
        $this->transaction($item, 1, '2026-10-20 01:00:00');
        $failing = new class(app(AutomationLog::class)) extends InventoryWorkflowService
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
                throw new RuntimeException('permanent boom');
            }
        };
        $failingService = new StockRiskAutomationService($failing, app(AutomationLog::class));
        $reference = CarbonImmutable::parse('2026-10-26 08:00:00', self::MANILA);

        try {
            $failingService->generateWeeklySummary($reference);
            $this->fail('The permanent failure was expected to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('permanent boom', $exception->getMessage());
        }

        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertDatabaseCount('weekly_stock_summary_items', 0);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'permanent_failure', 'attempt_count' => 1]);
        $this->assertSame(1, DB::table('manual_actions')->where('status', 'open')->count());

        // Rerun is blocked while the manual action is open, even with a healthy service.
        try {
            app(StockRiskAutomationService::class)->generateWeeklySummary($reference);
            $this->fail('A rerun must be blocked while the manual action is open.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Resolve the related manual action', $exception->getMessage());
        }
        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertSame(1, DB::table('manual_actions')->count());

        DB::table('manual_actions')->update(['status' => 'resolved', 'updated_at' => now()]);
        $summary = app(StockRiskAutomationService::class)->generateWeeklySummary($reference);

        $this->assertSame('2026-10-19', $summary->week_start);
        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseHas('automation_runs', ['idempotency_key' => 'weekly-stock-risk:2026-10-19', 'status' => 'completed']);
    }

    public function test_artisan_command_generates_the_completed_week_and_is_safe_to_repeat(): void
    {
        $risk = $this->item('CMD-RISK', 5, '2026-10-01 00:00:00');
        $this->transaction($risk, 2, '2026-10-21 02:00:00');
        $safe = $this->item('CMD-SAFE', 5, '2026-10-01 00:00:00');
        $this->transaction($safe, 50, '2026-10-21 02:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-26 08:00:00', self::MANILA));

        $this->artisan('inventory:weekly-risk')
            ->expectsOutput('Generated week 2026-10-19 to 2026-10-25: 1 at-risk item(s).')
            ->assertExitCode(0);

        $summary = DB::table('weekly_stock_summaries')->first();
        $this->assertSame(['2026-10-19', '2026-10-25', 1], [$summary->week_start, $summary->week_end, (int) $summary->risk_item_count]);
        $rows = $this->summaryRows($summary);
        $this->assertEqualsWithDelta(2.0, (float) $rows[$risk]->on_hand_quantity, 0.0001);
        $this->assertEqualsWithDelta(50.0, (float) $rows[$safe]->on_hand_quantity, 0.0001);

        $this->artisan('inventory:weekly-risk')
            ->expectsOutput('Generated week 2026-10-19 to 2026-10-25: 1 at-risk item(s).')
            ->assertExitCode(0);

        $this->assertDatabaseCount('weekly_stock_summaries', 1);
        $this->assertDatabaseCount('weekly_stock_summary_items', 2);
        $this->assertDatabaseCount('automation_runs', 1);
        $this->assertSame(1, DB::table('workflow_events')->where('event_type', 'weekly_stock_summary.generated')->count());
    }

    public function test_artisan_command_rejects_failure_simulation_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('inventory:weekly-risk', ['--simulate-transient' => true])
            ->expectsOutput('Failure simulation is available only in local or testing environments.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertDatabaseCount('automation_runs', 0);
    }

    public function test_artisan_command_returns_failure_and_routes_to_manual_action_on_permanent_failure(): void
    {
        $item = $this->item('CMD-FAIL', 5, '2026-10-01 00:00:00');
        $this->transaction($item, 1, '2026-10-20 01:00:00');
        $this->app->bind(InventoryWorkflowService::class, fn () => new class(app(AutomationLog::class)) extends InventoryWorkflowService
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
                throw new RuntimeException('permanent boom');
            }
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-26 08:00:00', self::MANILA));

        $this->artisan('inventory:weekly-risk')
            ->expectsOutput('Weekly summary failed. Review the manual-action queue and application logs.')
            ->assertExitCode(1);

        $this->assertDatabaseCount('weekly_stock_summaries', 0);
        $this->assertSame(1, DB::table('manual_actions')->where('status', 'open')->count());
    }

    public function test_weekly_events_are_valid_structured_json_with_required_fields(): void
    {
        $item = $this->item('LOG-1', 5, '2026-10-01 00:00:00');
        $this->transaction($item, 1, '2026-10-20 01:00:00');

        $this->generateForOct26();

        $entries = array_values(array_filter($this->automationLogEntries(), fn (array $e): bool => $e['event'] === 'weekly_stock_summary.generated'));
        $this->assertCount(1, $entries);
        $entry = $entries[0];
        $this->assertSame('info', $entry['level']);
        $this->assertSame('weekly-stock-risk:2026-10-19', $entry['idempotency_key']);
        $this->assertSame(1, $entry['attempt']);
        $this->assertSame('completed', $entry['outcome']);
        $this->assertSame(1, $entry['risk_item_count']);
        $this->assertIsInt($entry['automation_run_id']);
        $this->assertIsInt($entry['duration_ms']);
        $this->assertNotEmpty($entry['correlation_id']);
        $this->assertSame('2026-10-19', $entry['week_start']);
        $this->assertSame('2026-10-25', $entry['week_end']);
    }

    public function test_weekly_schedule_is_registered_for_monday_0800_manila_with_overlap_protection(): void
    {
        $event = $this->weeklyEvent();

        $this->assertSame('0 8 * * 1', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertGreaterThan(0, $event->expiresAt);
    }

    public function test_weekly_schedule_is_due_only_on_monday_0800_manila(): void
    {
        $event = $this->weeklyEvent();
        $dueAt = function (string $manilaTime) use ($event): bool {
            $this->travelTo(CarbonImmutable::parse($manilaTime, self::MANILA));

            return $event->isDue($this->app);
        };

        $this->assertTrue($dueAt('2026-10-26 08:00:00'));
        $this->assertFalse($dueAt('2026-10-26 07:59:00'));
        $this->assertFalse($dueAt('2026-10-26 09:00:00'));
        $this->assertFalse($dueAt('2026-10-27 08:00:00'));
        $this->assertFalse($dueAt('2026-10-25 08:00:00'));
        // 08:00 Manila is 00:00 UTC, so 08:00 UTC Monday must not fire.
        $this->assertFalse($dueAt('2026-10-26 16:00:00'));
    }

    private function weeklyEvent(): object
    {
        $matches = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => str_contains((string) $event->command, 'inventory:weekly-risk'),
        ));
        $this->assertCount(1, $matches);

        return $matches[0];
    }

    private function generateForOct26(): object
    {
        return app(StockRiskAutomationService::class)
            ->generateWeeklySummary(CarbonImmutable::parse('2026-10-26 08:00:00', self::MANILA));
    }

    /** @return array<int, object> summary item rows keyed by item id */
    private function summaryRows(object $summary): array
    {
        $rows = [];
        foreach (DB::table('weekly_stock_summary_items')->where('summary_id', $summary->id)->orderBy('item_id')->get() as $row) {
            $rows[(int) $row->item_id] = $row;
        }

        return $rows;
    }

    private function item(string $sku, float $threshold, string $createdAtUtc, bool $approved = true): int
    {
        return (int) DB::table('inventory_items')->insertGetId([
            'sku' => $sku,
            'name' => $sku,
            'unit' => 'piece',
            'on_hand_quantity' => 0,
            'reorder_threshold' => $threshold,
            'suggested_reorder_quantity' => 10,
            'is_approved' => $approved,
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);
    }

    private function transaction(int $itemId, float $resulting, string $createdAtUtc): void
    {
        DB::table('stock_transactions')->insert([
            'item_id' => $itemId,
            'transaction_type' => 'adjustment',
            'quantity_delta' => 0,
            'resulting_quantity' => $resulting,
            'reason' => 'Test fixture',
            'actor_label' => 'Test',
            'created_at' => $createdAtUtc,
            'updated_at' => $createdAtUtc,
        ]);
    }

    private function reorder(int $itemId, string $status, string $changedAtUtc): void
    {
        $alertId = DB::table('inventory_alerts')->insertGetId([
            'item_id' => $itemId,
            'episode_number' => 1,
            'status' => 'open',
            'triggered_at' => $changedAtUtc,
            'created_at' => $changedAtUtc,
            'updated_at' => $changedAtUtc,
        ]);
        DB::table('reorder_requests')->insert([
            'item_id' => $itemId,
            'source_alert_id' => $alertId,
            'suggested_quantity' => 10,
            'status' => $status,
            'status_changed_at' => $changedAtUtc,
            'created_at' => $changedAtUtc,
            'updated_at' => $changedAtUtc,
        ]);
    }
}
