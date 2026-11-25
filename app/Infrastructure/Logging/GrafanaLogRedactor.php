<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use JsonException;
use Monolog\LogRecord;
use RuntimeException;

/**
 * Builds a record's Grafana payload with the values of listed keys replaced, by key name only.
 */
final readonly class GrafanaLogRedactor
{
    private const string REDACTED = '[REDACTED]';

    private const string REDACTED_KEYS = '/(?:^|_)(?:password|secret|token|authorization|cookie|api_key|credential|signature|response|raw_response|ip|ip_hash|ip_address|email_hash)$/';

    private const string CAMEL_CASE_BOUNDARY = '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/';

    public function __construct(private RailwayJsonFormatter $formatter) {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function redact(LogRecord $record): array
    {
        // intentional: redacts the decoded JSON because objects only expose their keys once encoded.
        /** @var array<array-key, mixed> $payload */
        $payload = \json_decode($this->formatter->format($record), true, flags: JSON_THROW_ON_ERROR);

        foreach (['context', 'extra'] as $section) {
            if (\is_array($payload[$section] ?? null)) {
                $payload[$section] = $this->redactValues($payload[$section]);
            }
        }

        return $payload;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactValues(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            $redacted[$key] = match (true) {
                $this->isRedactedKey((string) $key) => self::REDACTED,
                \is_array($value) => $this->redactValues($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function isRedactedKey(string $key): bool
    {
        $snakeKey = \mb_strtolower((string) \preg_replace(self::CAMEL_CASE_BOUNDARY, '_', $key));

        return \preg_match(self::REDACTED_KEYS, $snakeKey) === 1;
    }
}
