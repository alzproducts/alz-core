<?php

declare(strict_types=1);

namespace App\Application\Conversion\UseCases;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles permanent failure of quote conversion upload.
 *
 * Marks the action as failed so the stale-action cleanup job stops resetting it.
 * Best-effort — marking failure must never throw past this boundary, otherwise
 * the framework's `failed()` callback would mask the original exception.
 */
final readonly class HandleQuoteConversionFailureUseCase
{
    public function __construct(
        private ContactSubmissionActionRepositoryInterface $actionRepository,
        private LoggerInterface $logger,
    ) {}

    public function execute(string $submissionId, string $actionId, string $exceptionMessage, int $attempts): void
    {
        $this->logger->error('Quote conversion permanently failed', [
            'submission_id' => $submissionId,
            'action_id' => $actionId,
            'exception' => $exceptionMessage,
            'attempts' => $attempts,
        ]);

        $this->markActionFailed($actionId, $exceptionMessage, $attempts);
    }

    private function markActionFailed(string $actionId, string $exceptionMessage, int $attempts): void
    {
        try {
            $this->actionRepository->markFailed(
                $actionId,
                "Retries exhausted after {$attempts} attempts: {$exceptionMessage}",
            );
        } catch (Throwable $e) { // @ignoreException - must not throw; cleanup job will reset stale rows
            $this->logger->critical('Failed to mark quote conversion action as failed after exhaustion', [
                'action_id' => $actionId,
                'original_exception' => $exceptionMessage,
                'mark_failed_exception' => $e::class,
                'mark_failed_message' => $e->getMessage(),
            ]);
        }
    }
}
