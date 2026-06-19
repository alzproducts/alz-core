<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\PricingUpdate\Results\FailedPriceUpdateResult;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductSellingPricesUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Psr\Log\LoggerInterface;

/**
 * Apply one product's selling-price updates to ShopWired.
 *
 * A thin delivery wrapper around UpdateProductSellingPricesUseCase — one queued job
 * per product, fanned out by DispatchBulkSellingPriceJobsUseCase. The use case does
 * NOT throw on transient ShopWired failures; it returns them in the result, so this
 * job rethrows them as a transient outage to drive retries and — after $tries —
 * land in failed_jobs, rather than silently dropping the price change.
 */
final class UpdateSellingPriceBatchJob extends AbstractJob
{
    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public int $timeout = 60;

    /**
     * @param IntId $productId Owning product external ID — observability only; the
     *                         use case resolves the product internally from the SKUs
     * @param non-empty-list<UpdatePriceCommand> $commands
     */
    public function __construct(
        public readonly IntId $productId,
        public readonly array $commands,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleDatabaseExceptions(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * @throws ResourceNotFoundException When the SKU's product is not found locally
     * @throws InvalidApiResponseException When API response parsing fails (contract violation)
     * @throws ExternalServiceUnavailableException When transport init fails or any SKU failed transiently (drives retry)
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws RecordNotFoundException When product row not found in database
     * @throws InvalidCustomFieldValueException When custom field mapping fails during product lookup
     */
    public function handle(UpdateProductSellingPricesUseCase $useCase, LoggerInterface $logger): void
    {
        try {
            $result = $useCase->execute($this->commands);
        } catch (ValidationFailedException $e) {
            // Bad operator input (VAT round-trip / price relationships) is permanent — fail
            // straight to failed_jobs rather than burning all $tries retrying an unfixable batch.
            $this->fail($e);

            return;
        }

        $this->logPermanentFailures($result->permanentFailures, $logger);

        // The use case returns transient API failures instead of throwing — rethrow as a
        // transient outage so the queue retries instead of silently dropping the price change.
        if ($result->temporaryFailures !== []) {
            throw new ExternalServiceUnavailableException('Shopwired');
        }
    }

    /**
     * @param list<FailedPriceUpdateResult> $failures
     */
    private function logPermanentFailures(array $failures, LoggerInterface $logger): void
    {
        if ($failures === []) {
            return;
        }

        $logger->error('Bulk selling price update: permanent per-SKU failures', [
            'product_id' => $this->productId->value,
            'failures' => \array_map(
                static fn(FailedPriceUpdateResult $failure): array => ['sku' => $failure->sku?->value, 'error' => $failure->error],
                $failures,
            ),
        ]);
    }
}
