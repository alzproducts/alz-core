<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
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
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(LongWaitDetected $event): void
    {
        $this->chat->sendAlert(
            AlertAudience::Admin,
            new AlertNotificationDataDTO(
                title: 'Queue Long Wait Detected',
                message: "Queue `{$event->queue}` on connection `{$event->connection}` has exceeded its wait threshold.",
                context: [
                    'connection' => $event->connection,
                    'queue' => $event->queue,
                ],
                firedAt: new DateTimeImmutable(),
            ),
        );
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
