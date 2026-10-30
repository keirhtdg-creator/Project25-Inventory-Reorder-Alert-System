<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Log;

abstract class TestCase extends BaseTestCase
{
    protected string $automationLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep automation log output out of storage/logs and readable by tests.
        $this->automationLogPath = tempnam(sys_get_temp_dir(), 'automation-log-');
        config(['logging.channels.automation.path' => $this->automationLogPath]);
        Log::forgetChannel('automation');
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('automation');
        if (isset($this->automationLogPath) && is_file($this->automationLogPath)) {
            unlink($this->automationLogPath);
        }

        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> One decoded JSON object per line of the automation log. */
    protected function automationLogEntries(): array
    {
        $lines = file($this->automationLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
    }
}
