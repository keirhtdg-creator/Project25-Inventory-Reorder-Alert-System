<?php

/*
 * Scheduler harness for the MariaDB scheduler execution test. Usage:
 *   php scheduler_harness.php run <Asia/Manila time>   run the real `schedule:run` with the clock fixed
 *   php scheduler_harness.php lock|unlock|exists <command substring>   inspect the real overlap mutex
 *
 * Only the harness process has a fixed clock. Commands started by `schedule:run` are real
 * `php artisan ...` child processes that use the real clock. Application code and schedule
 * definitions are not modified. It refuses to run against any other database.
 */

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

[, $action, $argument] = $argv;

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::selectOne('select database() as name')->name;
if ($database !== 'inventory_reorder_alerts_concurrency_test') {
    echo json_encode(['ok' => false, 'error' => 'Refusing to run against database '.$database]);
    exit(2);
}

if ($action === 'run') {
    Carbon::setTestNow(Carbon::parse($argument, 'Asia/Manila'));
    $exitCode = Artisan::call('schedule:run');
    echo json_encode(['ok' => true, 'exit_code' => $exitCode, 'output' => Artisan::output(), 'fixed_now' => Carbon::now()->toIso8601String()]);
    exit(0);
}

Artisan::call('schedule:list');
$events = array_values(array_filter(
    app(Schedule::class)->events(),
    fn ($event): bool => str_contains((string) $event->command, $argument),
));
if (count($events) !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Expected exactly one scheduled event, found '.count($events)]);
    exit(3);
}
$event = $events[0];

$result = match ($action) {
    'lock' => $event->mutex->create($event),
    'unlock' => $event->mutex->forget($event) ?? true,
    'exists' => $event->mutex->exists($event),
};
echo json_encode(['ok' => true, 'result' => $result, 'mutex_class' => $event->mutex::class]);
