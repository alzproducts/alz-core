<?php

declare(strict_types=1);

namespace App\Application\Conversion\CallTracking\UseCases;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingActionRepositoryInterface;
use App\Application\Contracts\ErrorReporterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles permanent failure of call-lead conversion upload.
 *
 * Marks the action failed so the stale-action cleanup job stops resetting it.
 * Best-effort — marking failure must never throw past this boundary, otherwise
 * Laravel's `failed()` callback would mask the original exception. The
 * `Queue::failing` hook already captures the original job exception; this
 * use case explicitly reports the secondary `markFailed` failure since that
 * path is otherwise swallowed.
 */
final readonly class HandleCallLeadConversionFailureUseCase
{
    public function __construct(
        private CallTrackingActionRepositoryInterface $actionRepository,
        private ErrorReporterInterface $errorReporter,
        private LoggerInterface $logger,
    ) {}

    public function execute(string $visitId, string $actionId, string $exceptionMessage, int $attempts): void
    {
        $this->logger->error('Call lead conversion permanently failed', [
            'visit_id' => $visitId,
            'action_id' => $actionId,
            'exception' => $exceptionMessage,
            'attempts' => $attempts,
        ]);

        $this->markActionFailed($visitId, $actionId, $exceptionMessage, $attempts);
    }

    private function markActionFailed(string $visitId, string $actionId, string $exceptionMessage, int $attempts): void
    {
        try {
            $this->actionRepository->markFailed(
                $actionId,
                "Retries exhausted after {$attempts} attempts: {$exceptionMessage}",
            );
        } catch (Throwable $e) { // @ignoreException - must not throw; cleanup job will reset stale rows
            $context = [
                'visit_id' => $visitId,
                'action_id' => $actionId,
                'attempts' => $attempts,
                'original_exception' => $exceptionMessage,
                'mark_failed_exception' => $e::class,
                'mark_failed_message' => $e->getMessage(),
            ];
            $this->logger->critical('Failed to mark call lead conversion action as failed after exhaustion', $context);
            $this->errorReporter->report($e, $context);
        }
    }
}
