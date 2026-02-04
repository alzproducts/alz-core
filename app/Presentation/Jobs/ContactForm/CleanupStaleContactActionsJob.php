<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\ContactForm;

use App\Application\Contracts\ContactSubmission\ContactSubmissionActionRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cleans up contact submission actions stuck in 'processing' status.
 *
 * Scheduled to run hourly. Finds actions that have been processing
 * for longer than the threshold (1 hour) and resets them to 'pending',
 * then re-dispatches the processing job.
 *
 * This handles edge cases like:
 * - Worker crash during processing
 * - Network partition preventing job completion
 * - HelpScout call succeeded but DB update failed
 *
 * Exception Strategy:
 * - ExternalServiceUnavailableException: Retry with backoff (transient DB issue)
 * - Per-action failures: Log and continue (don't fail entire batch)
 * - Unexpected errors: Fail immediately (code needs fixing)
 */
final class CleanupStaleContactActionsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const int STALE_THRESHOLD_HOURS = 1;

    /**
     * Maximum attempts before permanent failure.
     */
    public int $tries = 3;

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 120;

    /**
     * Backoff delays in seconds.
     * 1min, 5min - quick retries for transient DB issues.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('default');
    }

    /**
     * @throws ExternalServiceUnavailableException On transient DB failure (triggers retry)
     * @throws Throwable On unexpected errors (fails immediately)
     */
    public function handle(ContactSubmissionActionRepositoryInterface $repository): void
    {
        try {
            $threshold = new DateTimeImmutable(\sprintf('-%d hours', self::STALE_THRESHOLD_HOURS));
            $staleActions = $repository->findStaleProcessing($threshold);

            if ($staleActions === []) {
                return;
            }

            Log::info('Found stale contact submission actions', [
                'count' => \count($staleActions),
                'threshold_hours' => self::STALE_THRESHOLD_HOURS,
            ]);

            $resetCount = 0;
            $failedCount = 0;

            foreach ($staleActions as $action) {
                try {
                    $this->resetAndRedispatch($repository, $action);
                    $resetCount++;
                } catch (DatabaseOperationFailedException $e) {
                    // Log and continue - don't let one failure stop others
                    Log::error('Failed to reset stale contact action', [
                        'action_id' => $action['action_id'],
                        'submission_id' => $action['parent_id'],
                        'error' => $e->getMessage(),
                    ]);
                    $failedCount++;
                }
            }

            Log::info('Completed stale contact action cleanup', [
                'reset_count' => $resetCount,
                'failed_count' => $failedCount,
                'total_found' => \count($staleActions),
            ]);
        } catch (ExternalServiceUnavailableException $e) {
            // Transient DB failure - retry with backoff
            Log::warning('Database unavailable during stale action cleanup', [
                'retry_after' => $e->retryAfter,
                'attempt' => $this->attempts(),
            ]);

            throw $e; // Let Laravel handle with backoff
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in CleanupStaleContactActionsJob - code update required', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Reset a single action and re-dispatch its processing job.
     *
     * @param array{action_id: string, parent_id: string} $action
     *
     * @throws DatabaseOperationFailedException On reset failure
     * @throws ExternalServiceUnavailableException On transient DB failure
     */
    private function resetAndRedispatch(
        ContactSubmissionActionRepositoryInterface $repository,
        array $action,
    ): void {
        Log::warning('Resetting stale contact action', [
            'action_id' => $action['action_id'],
            'submission_id' => $action['parent_id'],
        ]);

        $repository->resetToPending($action['action_id']);

        ProcessContactSubmissionJob::dispatch(
            $action['parent_id'],
            $action['action_id'],
        );
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('CleanupStaleContactActionsJob exhausted all retries', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
