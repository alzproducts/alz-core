<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Queue\HorizonQueueWaitInputsReader;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;
use Throwable;

/**
 * Sends a Slack admin alert when Horizon detects a queue exceeding its wait threshold.
 *
 * Not queued — LongWaitDetected fires when the queue is backed up,
 * so queuing the alert would be counterproductive. Runs synchronously
 * from the Horizon master process.
 */
final class HorizonLongWaitSlackListener
{
    public function __construct(
        private readonly ChatNotificationInterface $chat,
        private readonly HorizonQueueWaitInputsReader $waitInputs,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(LongWaitDetected $event): void
    {
        $threshold = $this->thresholdFor($event);
        $context = $this->contextFor($event, $threshold);

        Log::warning('Queue long wait detected', $context);

        $this->chat->sendAlert(
            AlertAudience::Admin,
            new AlertNotificationDataDTO(
                title: 'Queue Long Wait Detected',
                message: "Queue `{$event->queue}` on connection `{$event->connection}` has exceeded its wait threshold: estimated wait {$event->seconds}s against a {$threshold}s threshold (estimate = ready jobs × mean runtime ÷ worker processes).",
                context: $context,
                firedAt: new DateTimeImmutable(),
            ),
        );
    }

    /**
     * @return array<string, float|int|string>
     */
    private function contextFor(LongWaitDetected $event, int $threshold): array
    {
        return [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'estimated_wait_seconds' => $event->seconds,
            'threshold_seconds' => $threshold,
            ...$this->waitInputs->read($event->connection, $event->queue)->toContext(),
        ];
    }

    /**
     * Horizon's own fallback when `horizon.waits` has no entry for the queue is 60 seconds.
     */
    private function thresholdFor(LongWaitDetected $event): int
    {
        $configured = \config("horizon.waits.{$event->connection}:{$event->queue}");

        return \is_numeric($configured) ? (int) $configured : 60;
    }

    public function failed(LongWaitDetected $event, Throwable $e): void
    {
        Log::error('Could not send queue long wait Slack notification', [
            'connection' => $event->connection,
            'queue' => $event->queue,
            'exception' => $e->getMessage(),
        ]);
    }
}
