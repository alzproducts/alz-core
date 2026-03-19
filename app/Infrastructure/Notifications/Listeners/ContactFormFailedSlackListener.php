<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications\Listeners;

use App\Application\Contracts\ChatNotificationInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionRepositoryInterface;
use App\Domain\ContactSubmission\Events\ContactFormProcessingFailedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends Slack notification when contact form processing fails.
 *
 * Uses aggressive retry strategy - failure notifications are critical.
 * Backoff: 10min, 1hr, 2hr (~3hr total coverage).
 */
final class ContactFormFailedSlackListener implements ShouldQueue
{
    public int $tries = 4;

    /** @var array<int> 1min, 10min, 1hr, 2hr */
    public array $backoff = [60, 600, 3600, 7200];

    public function __construct(
        private readonly ContactSubmissionRepositoryInterface $submissionRepository,
        private readonly ChatNotificationInterface $chat,
    ) {}

    /**
     * @throws ResourceNotFoundException When submission not found
     * @throws MalformedStoredDataException When stored data is corrupted
     * @throws InvalidConfigurationException When Slack channel is not configured
     * @throws ExternalServiceUnavailableException On transient database or Slack delivery failure
     */
    public function handle(ContactFormProcessingFailedEvent $event): void
    {
        $submission = $this->submissionRepository->findById($event->submissionId);

        $this->chat->sendContactFormFailed(
            submission: $submission,
            submissionId: $event->submissionId,
            errorMessage: $event->exceptionMessage,
            emailValid: $event->emailValid,
        );
    }

    public function failed(ContactFormProcessingFailedEvent $event, Throwable $e): void
    {
        Log::error('Could not send failure notification', [
            'submission_id' => $event->submissionId,
            'exception' => $e->getMessage(),
        ]);
    }
}
