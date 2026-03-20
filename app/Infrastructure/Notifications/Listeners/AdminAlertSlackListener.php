<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Notifications\Events\AdminAlertEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a Slack admin alert when AdminAlertEvent is fired.
 *
 * Queued independently so notification failures never affect the triggering operation.
 */
final class AdminAlertSlackListener implements ShouldQueue
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
    public function handle(AdminAlertEvent $event): void
    {
        $this->chat->sendAdminAlert(
            title: $event->title,
            message: $event->message,
            context: $event->context,
            firedAt: $event->firedAt,
        );
    }

    public function failed(AdminAlertEvent $event, Throwable $e): void
    {
        Log::error('Could not send admin alert Slack notification', [
            'title' => $event->title,
            'exception' => $e->getMessage(),
        ]);
    }
}
