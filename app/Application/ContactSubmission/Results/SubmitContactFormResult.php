<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\Results;

/**
 * Result of submitting a contact form.
 *
 * Contains the IDs needed for:
 * - Response to client (submissionId as reference)
 * - Job dispatch (both IDs for processing)
 */
final readonly class SubmitContactFormResult
{
    public function __construct(
        public string $submissionId,
        public string $actionId,
    ) {}
}
