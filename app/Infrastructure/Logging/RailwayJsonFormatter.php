<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Override;
use RuntimeException;

/**
 * JSON formatter that outputs Railway-compatible structured logs.
 *
 * Railway expects: {"message": "...", "level": "error", ...}
 * - `level` must be a lowercase string: "debug", "info", "warn", "error"
 *
 * Monolog's default JsonFormatter outputs:
 * - `level` as numeric (e.g., 400 for ERROR)
 * - `level_name` as uppercase (e.g., "ERROR")
 *
 * This formatter transforms the output to match Railway's expectations.
 *
 * @see https://docs.railway.com/guides/logs
 */
final class RailwayJsonFormatter extends JsonFormatter
{
    /**
     * Map Monolog levels to Railway's expected lowercase strings.
     *
     * @var array<int, string>
     */
    private const array LEVEL_MAP = [
        Level::Debug->value => 'debug',
        Level::Info->value => 'info',
        Level::Notice->value => 'info',
        Level::Warning->value => 'warn',
        Level::Error->value => 'error',
        Level::Critical->value => 'error',
        Level::Alert->value => 'error',
        Level::Emergency->value => 'error',
    ];

    /**
     * @throws RuntimeException
     */
    #[Override]
    public function format(LogRecord $record): string
    {
        $normalized = $this->normalizeRecord($record);

        // Replace Monolog's numeric level with Railway's lowercase string
        $normalized['level'] = self::LEVEL_MAP[$record->level->value];

        // Remove level_name as Railway doesn't use it
        unset($normalized['level_name']);

        return $this->toJson($normalized) . "\n";
    }
}
