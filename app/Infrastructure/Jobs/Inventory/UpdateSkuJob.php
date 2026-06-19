<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Inventory;

use App\Application\Inventory\UseCases\UpdateSkuUseCase;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;

/**
 * Asynchronously update a SKU across Linnworks and ShopWired.
 *
 * Uses ShouldBeUnique with a fixed ID to serialize ALL SKU updates.
 * This prevents GetNewItemNumber race conditions where concurrent jobs
 * could receive the same auto-generated sequential SKU.
 *
 * ⚠️ PRODUCTION ONLY: This job modifies LIVE Linnworks and ShopWired data.
 * The audit trail must be in the production database for traceability.
 * See UpdateSkusCommand docblock for details.
 *
 * Exception Strategy:
 * - SkuUpdateFailedException: Fail immediately (compensation failed, manual intervention)
 * - Domain data exceptions: Fail immediately (won't resolve on retry)
 * - TransientApiFailure: HandleApiExceptions middleware retries with backoff
 * - PermanentApiFailure: HandleApiExceptions middleware fails immediately
 *
 * @see UpdateSkuUseCase For orchestration and compensation logic
 */
final class UpdateSkuJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum attempts before permanent failure.
     */
    public int $tries = 3;

    /**
     * Maximum exceptions before permanent failure.
     */
    public int $maxExceptions = 3;

    /**
     * Unique lock duration in seconds.
     *
     * Set to max expected runtime + buffer. Jobs typically complete in <30s,
     * but external APIs may be slow. Lock auto-releases on completion.
     */
    public int $uniqueFor = 300;

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 120;
    /**
     * Backoff delays in seconds.
     *
     * 30s, 2min, 5min: Progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * Get the unique ID for this job.
     *
     * Returns a FIXED ID so all SKU update jobs share one lock.
     * This serializes all SKU updates to prevent race conditions
     * with Linnworks GetNewItemNumber (generates sequential SKUs).
     */
    public function uniqueId(): string
    {
        return 'update-sku';
    }

    public function __construct(
        public readonly UpdateSkuCommand $command,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * @throws Throwable On unexpected errors (Worker handles retry/fail)
     */
    public function handle(UpdateSkuUseCase $useCase): void
    {
        try {
            $useCase->execute($this->command);
        } catch (SkuUpdateFailedException|InvalidSkuException|SkuGenerationFailedException|DatabaseOperationFailedException|DuplicateRecordException $e) {
            // Permanent failures that won't resolve on retry — fail immediately.
            // SkuUpdateFailedException: compensation failed, systems out of sync.
            // Others: data/infrastructure issues requiring manual intervention.
            $this->fail($e);
        }
    }
}
