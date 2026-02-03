<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Events;

/**
 * Domain event fired when contact form processing fails after all retries.
 *
 * Listeners can use this for failure notifications, alerting, etc.
 */
final readonly class ContactFormProcessingFailedEvent
{
    public function __construct(
        public string $submissionId,
        public string $exceptionMessage,
    ) {}
}
