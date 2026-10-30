<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes Week 13 inventory automation events to the dedicated "automation" log channel.
 *
 * Correlation ID rules: inside an automation execution (begin()/end()) a new UUID is used;
 * otherwise the HTTP request's X-Correlation-ID is preserved; otherwise a one-off UUID is generated.
 */
class AutomationLog
{
    private ?string $executionId = null;

    public function begin(): void
    {
        $this->executionId = (string) Str::uuid();
    }

    public function end(): void
    {
        $this->executionId = null;
    }

    public function correlationId(): string
    {
        if ($this->executionId !== null) {
            return $this->executionId;
        }

        $httpId = request()->attributes->get('correlation_id');

        return is_string($httpId) && $httpId !== '' ? $httpId : (string) Str::uuid();
    }

    public function startTimer(): int
    {
        return hrtime(true);
    }

    public function elapsedMs(int $timer): int
    {
        return (int) round((hrtime(true) - $timer) / 1_000_000);
    }

    public function write(string $level, string $event, array $context = []): void
    {
        try {
            Log::channel('automation')->log($level, $event, ['correlation_id' => $this->correlationId()] + $context);
        } catch (Throwable $exception) {
            // Logging must never roll back or fail the workflow it describes.
            error_log('automation log write failed: '.$exception::class);
        }
    }
}
