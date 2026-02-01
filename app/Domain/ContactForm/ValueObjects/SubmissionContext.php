<?php

declare(strict_types=1);

namespace App\Domain\ContactForm\ValueObjects;

use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Contextual information about the submission request.
 *
 * IP address is captured server-side (always available).
 * Page URL is client-sent - nullable for resilience against JS bugs.
 */
final readonly class SubmissionContext
{
    public function __construct(
        public DateTimeImmutable $clientTimestamp,
        public string $ipAddress,
        public ?string $pageUrl = null,
        public ?string $referrerUrl = null,
        public ?string $userAgent = null,
    ) {
        Assert::notEmpty($ipAddress, 'IP address is required');
    }
}
