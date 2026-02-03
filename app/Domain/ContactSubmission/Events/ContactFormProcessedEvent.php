<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\Events;

use App\Domain\ValueObjects\IntId;

/**
 * Domain event fired when a contact form submission is successfully processed.
 *
 * "Processed" means HelpScout ticket was created successfully.
 * Listeners can use this for notifications, analytics, etc.
 */
final readonly class ContactFormProcessedEvent
{
    public function __construct(
        public string $submissionId,
        public IntId $conversationId,
        public string $customerName,
        public string $customerEmail,
    ) {}
}
