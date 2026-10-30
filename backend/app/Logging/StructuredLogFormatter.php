<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formats a log record as one flat, single-line JSON object:
 * timestamp, level and event first, then the (sanitized) context fields.
 */
class StructuredLogFormatter implements FormatterInterface
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY = '/password|passwd|secret|token|authorization|credential|api[_-]?key|cookie|private[_-]?key|^db_/i';

    private const MAX_STRING_LENGTH = 500;

    public function format(LogRecord $record): string
    {
        $context = $this->sanitizeValue($record->context);
        unset($context['timestamp'], $context['level'], $context['event']);

        $entry = [
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'level' => strtolower($record->level->getName()),
            'event' => $this->sanitizeString($record->message),
            ...array_filter($context, fn ($value): bool => $value !== null),
        ];

        return json_encode(
            $entry,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n";
    }

    public function formatBatch(array $records): string
    {
        return implode('', array_map(fn (LogRecord $record): string => $this->format($record), $records));
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match(self::SENSITIVE_KEY, $key)) {
            return self::REDACTED;
        }
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $childKey => $child) {
                $clean[$childKey] = $this->sanitizeValue($child, is_string($childKey) ? $childKey : null);
            }

            return $clean;
        }
        if (is_string($value)) {
            return $this->sanitizeString($value);
        }
        if (is_object($value)) {
            return $this->sanitizeString($value::class);
        }

        return $value;
    }

    private function sanitizeString(string $value): string
    {
        $value = preg_replace('/\b(password|passwd|pwd|secret|token|api[_-]?key|authorization)\s*[=:]\s*\S+/i', '$1='.self::REDACTED, $value) ?? $value;
        $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer '.self::REDACTED, $value) ?? $value;
        $value = preg_replace('#://[^/\s:@]+:[^@\s/]+@#', '://'.self::REDACTED.'@', $value) ?? $value;

        return mb_strlen($value) > self::MAX_STRING_LENGTH
            ? mb_substr($value, 0, self::MAX_STRING_LENGTH).'...'
            : $value;
    }
}
