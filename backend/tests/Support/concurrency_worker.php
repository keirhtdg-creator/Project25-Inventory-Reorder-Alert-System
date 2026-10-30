<?php

/*
 * Worker process for MariaDB concurrency tests. Each worker boots its own Laravel
 * application and opens its own database connection. Usage:
 * php concurrency_worker.php <itemId> <type> <quantity> <startAtMicrotime> <holdMs>
 *
 * The worker waits for a shared start time, then runs the real service call inside an
 * outer transaction that keeps the row locks for <holdMs> after the call, so a competing
 * worker must genuinely wait on the lock. Application code is not modified.
 */

use App\Services\InventoryWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

[, $itemId, $type, $quantity, $startAt, $holdMs] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::selectOne('select database() as name')->name;
if ($database !== 'inventory_reorder_alerts_concurrency_test') {
    echo json_encode(['ok' => false, 'error' => 'Refusing to run against database '.$database]);
    exit(2);
}

$result = ['ok' => false, 'connection_id' => (int) DB::selectOne('select connection_id() as id')->id];

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$result['started_at'] = microtime(true);

try {
    DB::transaction(function () use (&$result, $itemId, $type, $quantity, $holdMs): void {
        $result['response'] = app(InventoryWorkflowService::class)
            ->recordStockTransaction((int) $itemId, $type, (float) $quantity, 'Concurrency test');
        usleep(((int) $holdMs) * 1000);
    });
    $result['ok'] = true;
} catch (Throwable $exception) {
    $result['error_class'] = $exception::class;
    $result['error'] = $exception->getMessage();
}

$result['finished_at'] = microtime(true);
echo json_encode($result);
