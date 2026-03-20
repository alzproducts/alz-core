<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Dispatchers;

use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Jobs\ContactForm\ProcessContactSubmissionJob;
use Override;

/**
 * Queue-backed dispatcher for contact form processing.
 *
 * Translates Application-layer dispatch intents into concrete Laravel job dispatches.
 */
final readonly class QueuedContactFormDispatcher implements ContactFormDispatcherInterface
{
    #[Override]
    public function dispatchContactSubmissionProcessing(string $submissionId, string $actionId): void
    {
        ProcessContactSubmissionJob::dispatch($submissionId, $actionId);
    }
}
