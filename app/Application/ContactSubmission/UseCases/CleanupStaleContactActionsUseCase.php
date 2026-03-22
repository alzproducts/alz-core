<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\UseCases;

use App\Application\Contracts\ContactSubmission\ContactFormDispatcherInterface;
use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Find contact submission actions stuck in 'processing' and reset them.
 *
 * Batch-processing pattern: individual failures are logged and skipped,
 * allowing remaining actions to be reset. Handles edge cases like
 * worker crashes, network partitions, or partial DB updates.
 */
final readonly class CleanupStaleContactActionsUseCase
{
    private const int STALE_THRESHOLD_HOURS = 1;

    public function __construct(
        private ContactSubmissionActionRepositoryInterface $repository,
        private ContactFormDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function execute(): void
    {
        $threshold = new DateTimeImmutable(\sprintf('-%d hours', self::STALE_THRESHOLD_HOURS));
        $staleActions = $this->repository->findStaleProcessing($threshold);

        if ($staleActions === []) {
            return;
        }

        $this->logger->info('Found stale contact submission actions', [
            'count' => \count($staleActions),
            'threshold_hours' => self::STALE_THRESHOLD_HOURS,
        ]);

        $resetCount = 0;
        $failedCount = 0;

        foreach ($staleActions as $action) {
            try {
                $this->resetAndRedispatch($action);
                $resetCount++;
            } catch (DatabaseOperationFailedException $e) {
                $this->logger->error('Failed to reset stale contact action', [
                    'action_id' => $action['action_id'],
                    'submission_id' => $action['parent_id'],
                    'error' => $e->getMessage(),
                ]);
                $failedCount++;
            }
        }

        $this->logger->info('Completed stale contact action cleanup', [
            'reset_count' => $resetCount,
            'failed_count' => $failedCount,
            'total_found' => \count($staleActions),
        ]);
    }

    /**
     * Reset a single action and re-dispatch its processing job.
     *
     * @param array{action_id: string, parent_id: string} $action
     *
     * @throws DatabaseOperationFailedException On reset failure
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    private function resetAndRedispatch(array $action): void
    {
        $this->logger->warning('Resetting stale contact action', [
            'action_id' => $action['action_id'],
            'submission_id' => $action['parent_id'],
        ]);

        $this->repository->resetToPending($action['action_id']);

        $this->dispatcher->dispatchContactSubmissionProcessing(
            $action['parent_id'],
            $action['action_id'],
        );
    }
}
