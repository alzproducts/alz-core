<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Illuminate\Http\Client\Factory;
use JsonException;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Override;
use Throwable;

/**
 * Buffers redacted records and pushes them to Grafana Cloud's Loki API.
 *
 * Never throws to the code that logged; a failed send drops its batch.
 */
final class GrafanaLogHandler extends AbstractHandler
{
    private const int MAX_BUFFERED_RECORDS = 100;

    private const int MAX_BUFFERED_SECONDS = 60;

    private const int SEND_TIMEOUT_SECONDS = 2;

    /** @var list<array{time: string, level: string, line: string}> */
    private array $buffer = [];

    private float $oldestRecordTime = 0.0;

    private bool $shutdownRegistered = false;

    public function __construct(
        private readonly Factory $http,
        private readonly GrafanaLogRedactor $redactor,
        private readonly ?string $url,
        private readonly ?string $user,
        private readonly ?string $key,
        private readonly string $env,
        private readonly ?string $service,
        int|string|Level $level = Level::Info,
    ) {
        parent::__construct($level);
    }

    #[Override]
    public function isHandling(LogRecord $record): bool
    {
        return $this->isSwitchedOn() && parent::isHandling($record);
    }

    #[Override]
    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        try {
            $this->buffer($record);
        } catch (Throwable) { // @ignoreException - a record that cannot be redacted is never sent
        }

        return false;
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        // intentional: cleared before sending so a failed batch is not sent again.
        $batch = $this->buffer;
        $this->buffer = [];

        try {
            $this->send($batch);
        } catch (Throwable) { // @ignoreException - a failed send drops its batch unreported; stderr holds the records
        }
    }

    #[Override]
    public function close(): void
    {
        $this->flush();
    }

    private function isSwitchedOn(): bool
    {
        return ! \in_array('', [(string) $this->url, (string) $this->user, (string) $this->key], true);
    }

    /**
     * @throws Throwable
     */
    private function buffer(LogRecord $record): void
    {
        $recordTime = (float) $record->datetime->format('U.u');

        if ($this->isFlushDue($recordTime)) {
            $this->flush();
        }

        $entry = $this->entry($record);

        if ($this->buffer === []) {
            $this->oldestRecordTime = $recordTime;
        }

        $this->buffer[] = $entry;
        $this->flushAtShutdown();
    }

    /**
     * @return array{time: string, level: string, line: string}
     *
     * @throws Throwable
     */
    private function entry(LogRecord $record): array
    {
        $payload = $this->redactor->redact($record);

        return [
            'time' => $record->datetime->format('Uu') . '000',
            'level' => \is_string($payload['level'] ?? null) ? $payload['level'] : 'info',
            'line' => $this->encode($payload),
        ];
    }

    private function isFlushDue(float $now): bool
    {
        if ($this->buffer === []) {
            return false;
        }

        return \count($this->buffer) >= self::MAX_BUFFERED_RECORDS
            || $now - $this->oldestRecordTime >= self::MAX_BUFFERED_SECONDS;
    }

    private function flushAtShutdown(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        // intentional: destructors do not run after a fatal error.
        \register_shutdown_function($this->flush(...));
        $this->shutdownRegistered = true;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @throws JsonException
     */
    private function encode(array $payload): string
    {
        return \json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * @param list<array{time: string, level: string, line: string}> $batch
     *
     * @throws Throwable
     */
    private function send(array $batch): void
    {
        $this->http
            ->createPendingRequest()
            ->withBasicAuth((string) $this->user, (string) $this->key)
            ->connectTimeout(self::SEND_TIMEOUT_SECONDS)
            ->timeout(self::SEND_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->post((string) $this->url, ['streams' => $this->streams($batch)]);
    }

    /**
     * @param list<array{time: string, level: string, line: string}> $batch
     *
     * @return list<array{stream: array<string, string>, values: list<array{string, string}>}>
     */
    private function streams(array $batch): array
    {
        $valuesByLevel = [];

        foreach ($batch as $entry) {
            $valuesByLevel[$entry['level']][] = [$entry['time'], $entry['line']];
        }

        $streams = [];

        foreach ($valuesByLevel as $level => $values) {
            $streams[] = ['stream' => $this->labels($level), 'values' => $values];
        }

        return $streams;
    }

    /**
     * @return array<string, string>
     */
    private function labels(string $level): array
    {
        $labels = ['app' => 'alz-core', 'env' => $this->env, 'level' => $level];

        if ($this->service !== null && $this->service !== '') {
            $labels['service'] = $this->service;
        }

        return $labels;
    }
}
