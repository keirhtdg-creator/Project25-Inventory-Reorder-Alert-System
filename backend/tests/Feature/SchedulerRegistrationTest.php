<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SchedulerRegistrationTest extends TestCase
{
    public function test_aging_is_registered_daily_at_0800_manila_with_overlap_protection(): void
    {
        $event = $this->event('inventory:process-aging');

        $this->assertSame('0 8 * * *', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_weekly_summary_is_registered_monday_0800_manila_with_overlap_protection(): void
    {
        $event = $this->event('inventory:weekly-risk');

        $this->assertSame('0 8 * * 1', $event->expression);
        $this->assertSame('Asia/Manila', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(1440, $event->expiresAt);
    }

    public function test_aging_is_due_every_day_at_0800_manila_only(): void
    {
        $event = $this->event('inventory:process-aging');

        foreach (['2026-10-26', '2026-10-27', '2026-10-31', '2026-11-01'] as $day) {
            $this->travelTo(CarbonImmutable::parse("$day 08:00:00", 'Asia/Manila'));
            $this->assertTrue($event->isDue($this->app), "due on $day 08:00 Manila");
        }
        foreach (['2026-10-27 07:59:00', '2026-10-27 08:01:00', '2026-10-27 20:00:00', '2026-10-27 16:00:00'] as $time) {
            $this->travelTo(CarbonImmutable::parse($time, 'Asia/Manila'));
            $this->assertFalse($event->isDue($this->app), "not due at $time Manila");
        }
    }

    public function test_each_command_is_registered_exactly_once(): void
    {
        foreach (['inventory:process-aging', 'inventory:weekly-risk'] as $command) {
            $matches = array_filter(app(Schedule::class)->events(), fn ($e): bool => str_contains((string) $e->command, $command));
            $this->assertCount(1, $matches, $command);
        }
    }

    private function event(string $command): object
    {
        $matches = array_values(array_filter(
            app(Schedule::class)->events(),
            fn ($event): bool => str_contains((string) $event->command, $command),
        ));
        $this->assertCount(1, $matches);

        return $matches[0];
    }
}
