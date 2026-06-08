<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UpdateCostPriceBySupplier\UpdateCostPriceBySupplierUseCase;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Apply one supplier-chunk (≤100 SKUs) of cost-price updates to Linnworks.
 *
 * A thin delivery wrapper around UpdateCostPriceBySupplierUseCase — one queued job per chunk,
 * fanned out by DispatchBulkCostPriceJobsUseCase. Per-item failures stay inside the use-case
 * result (which dispatches reconciliation syncs). A whole-batch failure (nothing succeeded) is
 * re-thrown as a transient outage so the job retries and — after $tries — lands in failed_jobs,
 * rather than silently dropping the price change.
 */
final class UpdateCostPriceBatchJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     */
    public function __construct(
        public readonly string $supplierName,
        public readonly array $commands,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new HandleDatabaseExceptions(),
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * @throws ResourceNotFoundException When supplier not found in Linnworks
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When the whole batch failed to write to Linnworks (drives retry)
     * @throws DatabaseOperationFailedException On local DB query failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function handle(UpdateCostPriceBySupplierUseCase $useCase): void
    {
        try {
            $result = $useCase->execute($this->supplierName, $this->commands);
        } catch (ValidationFailedException $e) {
            // A bad SKU↔supplier link is permanent operator-input error — fail straight to
            // failed_jobs rather than burning all $tries retrying an unfixable batch.
            $this->fail($e);

            return;
        }

        // Retry only a genuine whole-batch Linnworks write outage. A permanently-unresolvable batch
        // or a local-DB-mirror failure are NOT retried — they mirror the synchronous endpoint
        // (failures logged + reconciliation syncs already dispatched), so retrying would only
        // re-record the audit trail and re-fire those syncs without recovering anything.
        if ($result->wholeBatchWriteFailed) {
            throw new ExternalServiceUnavailableException('Linnworks');
        }
    }
}
