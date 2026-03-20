<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Results\BatchUpdateResult;
use App\Application\Shopwired\UseCases\SetProductFreeDeliveryUseCase;
use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Exceptions\AllItemsFailedException;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Infrastructure\Jobs\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronously update free delivery custom field on ShopWired products.
 *
 * Processes a batch of SetFreeDeliveryCommand objects. Uses smart failure handling:
 * - **Permanent failures**: Logged and dropped (won't succeed on retry)
 * - **Temporary failures**: Re-queued as a new job with delay
 * - **All temporary failures**: Throws AllItemsFailedException for job retry
 *
 * @see SetProductFreeDeliveryUseCase For batch processing logic
 */
final class SetProductFreeDeliveryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 15min: progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 120;

    /**
     * Delay in seconds before re-queuing failed items.
     */
    private const int RETRY_DELAY_SECONDS = 300;

    /**
     * @param list<SetFreeDeliveryCommand> $commands Commands to process
     */
    public function __construct(
        public readonly array $commands,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Execute the job.
     *
     * @throws AllItemsFailedException When all items fail with temporary errors
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SetProductFreeDeliveryUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('SetProductFreeDeliveryJob starting', [
            'count' => \count($this->commands),
            'attempt' => $this->attempts(),
        ]);

        try {
            $result = $useCase->execute($this->commands);
            $this->handleResult($result, $logger);
        } catch (Throwable $e) {
            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Handle the UseCase result with smart retry logic.
     *
     * @param BatchUpdateResult<string|int> $result
     *
     * @throws AllItemsFailedException When all items fail with temporary errors
     */
    private function handleResult(BatchUpdateResult $result, LoggerInterface $logger): void
    {
        // All succeeded - nothing more to do
        if ($result->allSucceeded()) {
            $logger->info('SetProductFreeDeliveryJob completed successfully', [
                'succeeded' => $result->succeeded,
            ]);

            return;
        }

        // Log permanent failures (these won't be retried)
        if ($result->permanentFailures !== []) {
            $logger->warning('SetProductFreeDeliveryJob permanent failures (not retrying)', [
                'count' => \count($result->permanentFailures),
                'failures' => $result->permanentFailures,
            ]);
        }

        // Handle temporary failures
        if ($result->hasRetryableFailures()) {
            $this->handleTemporaryFailures($result, $logger);
        }
    }

    /**
     * Handle temporary failures with smart retry strategy.
     *
     * @param BatchUpdateResult<string|int> $result
     *
     * @throws AllItemsFailedException When all items failed with temporary errors
     */
    private function handleTemporaryFailures(BatchUpdateResult $result, LoggerInterface $logger): void
    {
        // If ALL items failed with temporary errors, throw to trigger job retry
        // This uses Laravel's built-in retry mechanism with backoff
        if ($result->succeeded === 0 && $result->permanentFailures === []) {
            $logger->warning('SetProductFreeDeliveryJob all items failed temporarily, will retry job', [
                'count' => \count($result->temporaryFailures),
                'attempt' => $this->attempts(),
            ]);

            throw new AllItemsFailedException($result, $result->total);
        }

        // Partial success: re-queue only the failed items as a new job
        $failedCommands = $this->extractFailedCommands($result);

        if ($failedCommands !== []) {
            $logger->info('SetProductFreeDeliveryJob re-queuing temporary failures', [
                'succeeded' => $result->succeeded,
                'retrying' => \count($failedCommands),
            ]);

            self::dispatch($failedCommands)->delay(\now()->addSeconds(self::RETRY_DELAY_SECONDS));
        }
    }

    /**
     * Extract commands for items that failed temporarily.
     *
     * @param BatchUpdateResult<string|int> $result
     *
     * @return list<SetFreeDeliveryCommand>
     */
    private function extractFailedCommands(BatchUpdateResult $result): array
    {
        $failedIdentifiers = $result->getRetryableIdentifiers();

        return \array_values(\array_filter(
            $this->commands,
            static fn(SetFreeDeliveryCommand $cmd): bool => \in_array($cmd->identifier, $failedIdentifiers, true),
        ));
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'count' => \count($this->commands),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('SetProductFreeDeliveryJob failed permanently', $context);
        } else {
            Log::critical('SetProductFreeDeliveryJob failed permanently', $context);
        }
    }
}
