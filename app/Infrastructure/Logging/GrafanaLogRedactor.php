<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use JsonException;
use Monolog\LogRecord;
use RuntimeException;

/**
 * Builds a record's Grafana level and JSON line with the values of listed keys replaced, by key name only.
 */
final readonly class GrafanaLogRedactor
{
    private const string REDACTED = '[REDACTED]';

    private const string REDACTED_KEYS = '/(?:^|_)(?:password|secret|token|authorization|cookie|api_key|credential|signature|response|raw_response|ip|ip_hash|ip_address|email_hash)$/';

    private const int JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(private RailwayJsonFormatter $formatter) {}

    /**
     * @return array{level: string, line: string}
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    public function redact(LogRecord $record): array
    {
        // intentional: redacts the decoded JSON because objects only expose their keys once encoded.
        /** @var array{level: string, context: array<array-key, mixed>, extra: array<array-key, mixed>} $payload */
        $payload = \json_decode($this->formatter->format($record), true, flags: JSON_THROW_ON_ERROR);

        $payload['context'] = $this->redactValues($payload['context']);
        $payload['extra'] = $this->redactValues($payload['extra']);

        return ['level' => $payload['level'], 'line' => \json_encode($payload, self::JSON_FLAGS)];
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
                \preg_match(self::REDACTED_KEYS, (string) $key) === 1 => self::REDACTED,
                \is_array($value) => $this->redactValues($value),
                default => $value,
            };
        }

        return $redacted;
    }
}
