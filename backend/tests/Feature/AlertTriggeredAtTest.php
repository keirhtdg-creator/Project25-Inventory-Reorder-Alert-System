<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * triggered_at must be assigned once, when the alert is created. On MariaDB the original column
 * silently carried ON UPDATE CURRENT_TIMESTAMP, which SQLite cannot reproduce, so this test runs
 * against the dedicated disposable MariaDB database only (CONCURRENCY_DB_DATABASE).
 */
class AlertTriggeredAtTest extends TestCase
{
    private const TEST_DATABASE = 'inventory_reorder_alerts_concurrency_test';

    private const TABLES = ['inventory_items', 'inventory_alerts', 'reorder_requests', 'stock_transactions', 'workflow_events'];

    private const MARKER = '2026-01-02 03:04:05';

    private string $previousConnection;

    /** @var array<string, int> */
    private array $maxIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('CONCURRENCY_DB_DATABASE') !== self::TEST_DATABASE) {
            $this->markTestSkipped('Set CONCURRENCY_DB_DATABASE='.self::TEST_DATABASE.' to run the MariaDB triggered_at test.');
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
            $this->maxIds[$table] = (int) DB::table($table)->max('id');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->previousConnection)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->maxIds as $table => $maxId) {
                DB::table($table)->where('id', '>', $maxId)->delete();
            }
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            DB::setDefaultConnection($this->previousConnection);
            DB::purge('concurrency');
        }

        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_column_has_no_auto_update_and_keeps_keys_and_indexes(): void
    {
        $column = DB::selectOne(
            'select column_type, is_nullable, column_default, extra from information_schema.columns
             where table_schema = ? and table_name = ? and column_name = ?',
            [self::TEST_DATABASE, 'inventory_alerts', 'triggered_at'],
        );

        $this->assertSame('timestamp', $column->column_type);
        $this->assertSame('NO', $column->is_nullable);
        $this->assertStringNotContainsStringIgnoringCase('on update', (string) $column->extra);

        $this->assertSame(1, DB::table('information_schema.key_column_usage')
            ->where('table_schema', self::TEST_DATABASE)
            ->where('table_name', 'inventory_alerts')
            ->where('column_name', 'item_id')
            ->where('referenced_table_name', 'inventory_items')
            ->count());
        $this->assertSame(['item_id', 'episode_number'], DB::table('information_schema.statistics')
            ->where('table_schema', self::TEST_DATABASE)
            ->where('table_name', 'inventory_alerts')
            ->where('index_name', 'inventory_alerts_item_id_episode_number_unique')
            ->where('non_unique', 0)
            ->orderBy('seq_in_index')
            ->pluck('column_name')
            ->all());

        $updated = DB::table('information_schema.columns')
            ->where('table_schema', self::TEST_DATABASE)
            ->where('table_name', 'inventory_alerts')
            ->whereIn('column_name', ['created_at', 'updated_at', 'acknowledged_at', 'resolved_at'])
            ->pluck('column_type', 'column_name');
        $this->assertCount(4, $updated);
    }

    public function test_triggered_at_is_unchanged_by_acknowledgement_resolution_and_later_updates(): void
    {
        $createdAt = Carbon::parse('2026-10-30 04:00:00', 'UTC');
        Carbon::setTestNow($createdAt);

        $itemId = (int) $this->postJson('/api/v1/items', [
            'sku' => 'TRIG-'.uniqid(), 'name' => 'Triggered-at item', 'unit' => 'piece',
            'on_hand_quantity' => 10, 'reorder_threshold' => 5, 'suggested_reorder_quantity' => 6,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/items/{$itemId}/transactions", ['type' => 'issue', 'quantity' => 6])->assertCreated();

        $alert = DB::table('inventory_alerts')->where('item_id', $itemId)->first();
        $this->assertSame('2026-10-30 04:00:00', $alert->triggered_at, 'Set once, at creation.');
        $this->assertSame('2026-10-30 04:00:00', $alert->created_at);
        $this->assertNull($alert->acknowledged_at);

        // A fixed, clearly old value: any server-side rewrite to "now" would be unmistakable.
        DB::table('inventory_alerts')->where('id', $alert->id)->update(['triggered_at' => self::MARKER]);

        Carbon::setTestNow($createdAt->copy()->addHour());
        $this->postJson("/api/v1/alerts/{$alert->id}/acknowledgement")->assertOk();
        $acknowledged = DB::table('inventory_alerts')->where('id', $alert->id)->first();
        $this->assertSame(self::MARKER, $acknowledged->triggered_at);
        $this->assertSame('acknowledged', $acknowledged->status);
        $this->assertSame('2026-10-30 05:00:00', $acknowledged->acknowledged_at);
        $this->assertSame('2026-10-30 05:00:00', $acknowledged->updated_at);
        $this->assertSame('2026-10-30 04:00:00', $acknowledged->created_at);

        $requestId = (int) DB::table('reorder_requests')->where('item_id', $itemId)->value('id');
        Carbon::setTestNow($createdAt->copy()->addHours(2));
        $this->postJson("/api/v1/reorder-requests/{$requestId}/review", ['decision' => 'approve'])->assertOk();
        $this->patchJson("/api/v1/reorder-requests/{$requestId}/procurement-status", ['status' => 'ordered'])->assertOk();
        $this->postJson("/api/v1/reorder-requests/{$requestId}/receipts", ['quantity' => 6])->assertOk();
        $this->postJson("/api/v1/reorder-requests/{$requestId}/close", ['resolution_note' => 'Done.'])->assertOk();

        $resolved = DB::table('inventory_alerts')->where('id', $alert->id)->first();
        $this->assertSame(self::MARKER, $resolved->triggered_at);
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('2026-10-30 06:00:00', $resolved->resolved_at);
        $this->assertSame('2026-10-30 06:00:00', $resolved->updated_at);
        $this->assertSame('2026-10-30 05:00:00', $resolved->acknowledged_at);
        $this->assertSame('2026-10-30 04:00:00', $resolved->created_at);

        // A later update that does not name triggered_at must not touch it either.
        DB::table('inventory_alerts')->where('id', $alert->id)->update(['updated_at' => '2026-10-31 00:00:00']);
        $later = DB::table('inventory_alerts')->where('id', $alert->id)->first();
        $this->assertSame(self::MARKER, $later->triggered_at);
        $this->assertSame('2026-10-31 00:00:00', $later->updated_at);
    }
}
