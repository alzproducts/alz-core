<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Notifications\DTOs\AlertNotificationDataDTO;
use App\Application\Notifications\Enums\AlertAudience;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Notifications\Events\ManagerAlertEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a Slack manager alert when ManagerAlertEvent is fired.
 *
 * Queued independently so notification failures never affect the triggering operation.
 */
final class ManagerAlertSlackListener implements ShouldQueue
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public function __construct(
        private readonly ChatNotificationInterface $chat,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(ManagerAlertEvent $event): void
    {
        $this->chat->sendAlert(
            AlertAudience::Manager,
            new AlertNotificationDataDTO(
                title: $event->title,
                message: $event->message,
                context: $event->context,
                firedAt: $event->firedAt,
            ),
        );
    }

    public function failed(ManagerAlertEvent $event, Throwable $e): void
    {
        Log::error('Could not send manager alert Slack notification', [
            'title' => $event->title,
            'exception' => $e->getMessage(),
        ]);
    }
}
