<?php

declare(strict_types=1);

namespace App\Application\Contracts\ContactSubmission;

/**
 * Dispatch contact form processing tasks.
 *
 * Application layer uses this to trigger async processing without
 * knowing the delivery mechanism (queue, inline, etc.).
 */
interface ContactFormDispatcherInterface
{
    public function dispatchContactSubmissionProcessing(string $submissionId, string $actionId): void;
}
