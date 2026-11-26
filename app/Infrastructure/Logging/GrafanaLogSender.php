<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use App\Application\Contracts\ErrorReporterInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Log\LogManager;
use JsonException;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;
use Override;
use RuntimeException;
use Throwable;

/**
 * Pushes redacted batches to Grafana Cloud's Loki API; a failure drops its batch and pauses sending.
 */
final class GrafanaLogSender extends AbstractHandler
{
    private const int SEND_TIMEOUT_SECONDS = 1;

    private const int PAUSE_SECONDS = 60;

    private ?CarbonImmutable $failedAt = null;

    /**
     * @param array<string, string> $labels
     */
    public function __construct(
        private readonly Factory $http,
        private readonly LogManager $logs,
        private readonly ErrorReporterInterface $errorReporter,
        private readonly GrafanaLogRedactor $redactor,
        private readonly string $url,
        private readonly string $user,
        private readonly string $key,
        private readonly array $labels,
    ) {
        parent::__construct();
    }

    #[Override]
    public function handle(LogRecord $record): bool
    {
        $this->handleBatch([$record]);

        return false;
    }

    /**
     * @param array<LogRecord> $records
     */
    #[Override]
    public function handleBatch(array $records): void
    {
        if ($this->failedAt?->addSeconds(self::PAUSE_SECONDS)->isFuture() === true) {
            return;
        }

        try {
            $response = $this->push($records);
        } catch (Throwable $e) { // @ignoreException - reported by class alone; its message can hold the URL or a record
            $this->recordFailure(['exception' => $e::class, 'dropped' => \count($records)]);

            return;
        }

        if ($response->successful()) {
            $this->recordSuccess();
        } else {
            $this->recordFailure(['status' => $response->status(), 'dropped' => \count($records)]);
        }
    }

    /**
     * @param array<LogRecord> $records
     *
     * @throws ConnectionException
     * @throws JsonException
     * @throws RuntimeException
     */
    private function push(array $records): Response
    {
        return $this->http
            ->createPendingRequest()
            ->withBasicAuth($this->user, $this->key)
            ->connectTimeout(self::SEND_TIMEOUT_SECONDS)
            ->timeout(self::SEND_TIMEOUT_SECONDS)
            ->withoutRedirecting()
            ->post($this->url, ['streams' => $this->streams($records)]);
    }

    /**
     * @param array<LogRecord> $records
     *
     * @return list<array{stream: array<string, string>, values: list<array{string, string}>}>
     *
     * @throws JsonException
     * @throws RuntimeException
     */
    private function streams(array $records): array
    {
        $valuesByLevel = [];

        foreach ($records as $record) {
            $entry = $this->redactor->redact($record);
            $valuesByLevel[$entry['level']][] = [$record->datetime->format('Uu') . '000', $entry['line']];
        }

        $streams = [];

        foreach ($valuesByLevel as $level => $values) {
            $streams[] = ['stream' => [...$this->labels, 'level' => $level], 'values' => $values];
        }

        return $streams;
    }

    private function recordSuccess(): void
    {
        if ($this->failedAt === null) {
            return;
        }

        $this->failedAt = null;
        $this->logs->channel('stderr')->info('Grafana log push recovered');
    }

    /**
     * @param array<string, int|string> $failure
     */
    private function recordFailure(array $failure): void
    {
        $alreadyReported = $this->failedAt !== null;
        $this->failedAt = CarbonImmutable::now();

        if ($alreadyReported) {
            return;
        }

        try {
            $this->logs->channel('stderr')->error('Grafana log push failed', $failure);
        } finally {
            $this->errorReporter->report(new RuntimeException('Grafana log push failed'), $failure);
        }
    }
}
