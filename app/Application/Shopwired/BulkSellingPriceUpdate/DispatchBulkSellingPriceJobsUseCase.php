<?php

declare(strict_types=1);

namespace App\Application\Shopwired\BulkSellingPriceUpdate;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SellingPriceUpdateDispatcherInterface;
use App\Application\Shopwired\BulkSellingPriceUpdate\Results\BulkSellingPriceDispatchResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Fan a flat set of SKU selling-price commands out into queued jobs — one job per
 * owning product, since the downstream use case requires all of a product's SKUs
 * in a single call.
 *
 * SKU→product resolution is all-or-nothing: any SKU that doesn't resolve to a
 * local product rejects the whole batch before a single job is dispatched.
 */
final readonly class DispatchBulkSellingPriceJobsUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private SellingPriceUpdateDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     *
     * @throws ValidationFailedException When any SKU does not resolve to a local product
     * @throws DatabaseOperationFailedException On SKU map query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(array $commands): BulkSellingPriceDispatchResult
    {
        $this->logger->info('Dispatching bulk selling price update jobs', [
            'sku_count' => \count($commands),
        ]);

        $result = $this->dispatchJobs($this->groupByProduct($commands), \count($commands));

        $this->logger->info('Bulk selling price update jobs dispatched', [
            'product_count' => $result->productCount,
            'sku_count' => $result->skuCount,
            'jobs_dispatched' => $result->jobsDispatched,
        ]);

        return $result;
    }

    /**
     * @param array<int, non-empty-list<UpdatePriceCommand>> $grouped Commands grouped by product external ID
     * @param int<0, max> $skuCount
     */
    private function dispatchJobs(array $grouped, int $skuCount): BulkSellingPriceDispatchResult
    {
        foreach ($grouped as $productId => $productCommands) {
            $this->dispatcher->dispatchSellingPriceBatch(IntId::fromTrusted($productId), $productCommands);
        }

        return new BulkSellingPriceDispatchResult(
            productCount: \count($grouped),
            skuCount: $skuCount,
            jobsDispatched: \count($grouped),
        );
    }

    /**
     * @param non-empty-list<UpdatePriceCommand> $commands
     *
     * @return array<int, non-empty-list<UpdatePriceCommand>> Commands grouped by product external ID
     *
     * @throws ValidationFailedException When any SKU does not resolve to a local product
     * @throws DatabaseOperationFailedException On SKU map query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function groupByProduct(array $commands): array
    {
        $productIdBySku = $this->buildSkuToProductMap();

        $grouped = [];
        $unresolved = [];
        foreach ($commands as $command) {
            $productId = $productIdBySku[$command->sku->value] ?? null;
            if ($productId === null) {
                $unresolved[] = $command->sku->value;

                continue;
            }
            $grouped[$productId][] = $command;
        }

        $this->rejectUnresolvedSkus($unresolved);

        return $grouped;
    }

    /**
     * Resolution is all-or-nothing: any unresolved SKU rejects the whole batch.
     *
     * @param list<string> $unresolved
     *
     * @throws ValidationFailedException When any SKU does not resolve to a local product
     */
    private function rejectUnresolvedSkus(array $unresolved): void
    {
        if ($unresolved !== []) {
            throw new ValidationFailedException(
                'CSV contains SKUs that do not resolve to any local product',
                ['unresolved_skus' => $unresolved],
            );
        }
    }

    /**
     * @return array<string, int> SKU value → product external ID
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function buildSkuToProductMap(): array
    {
        $bySku = [];
        foreach ($this->productRepo->getSkusGroupedByProductId() as $productId => $skus) {
            foreach ($skus as $sku) {
                $bySku[$sku] = $productId;
            }
        }

        return $bySku;
    }
}
