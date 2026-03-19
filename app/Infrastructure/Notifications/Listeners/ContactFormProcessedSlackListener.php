<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when contact form is successfully processed.
 */
final class ContactFormProcessedSlackListener implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        private readonly ChatNotificationInterface $chat,
    ) {}

    /**
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On Slack delivery failure
     */
    public function handle(ContactFormProcessedEvent $event): void
    {
        $this->chat->sendContactFormProcessed(
            conversationId: $event->conversationId,
            customerName: $event->customerName,
            customerEmail: $event->customerEmail,
        );
    }

    public function failed(ContactFormProcessedEvent $event, Throwable $e): void
    {
        Log::error('Could not send success notification', [
            'submission_id' => $event->submissionId,
            'exception' => $e->getMessage(),
        ]);
    }
}
