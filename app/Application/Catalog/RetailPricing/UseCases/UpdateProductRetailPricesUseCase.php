<?php

declare(strict_types=1);

namespace App\Application\Catalog\RetailPricing\UseCases;

use App\Application\Contracts\Catalog\ProductExtraDataRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateResult;
use App\Domain\Catalog\Product\Commands\UpdateRetailPriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Write per-SKU RRP to the database and dispatch ShopWired reconciliation.
 *
 * Uses a single bulk upsert — if it fails, all SKUs in the batch fail together.
 * After a successful write, reconciliation is dispatched as a job for proper retry semantics.
 */
final readonly class UpdateProductRetailPricesUseCase
{
    public function __construct(
        private ProductExtraDataRepositoryInterface $extraDataRepo,
        private ShopwiredSyncDispatcherInterface $syncDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param IntId $productId The product these SKUs belong to
     * @param list<UpdateRetailPriceCommand> $commands Per-SKU RRP updates
     *
     * @throws DatabaseOperationFailedException When bulk upsert fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function execute(IntId $productId, array $commands): PriceUpdateResult
    {
        if ($commands === []) {
            return new PriceUpdateResult(total: 0, succeeded: 0);
        }

        $count = \count($commands);
        $this->logger->info('Starting retail price update', [
            'product_id' => $productId->value, 'command_count' => $count,
        ]);

        $this->extraDataRepo->upsertRrpBulk($commands);
        $this->syncDispatcher->dispatchReconcileComparePrice($productId);

        $this->logger->info('Retail price update completed', [
            'product_id' => $productId->value, 'succeeded' => $count,
        ]);

        return new PriceUpdateResult(total: $count, succeeded: $count);
    }
}
