<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Product\Factories;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Factory for looking up Linnworks cost prices by SKU.
 *
 * Lazy-loads ALL cost prices on first access, then O(1) lookups.
 * Follows the same pattern as ProductCustomFieldFactory.
 *
 * **Lifecycle**: Register with `scoped()` binding to ensure fresh instance per
 * request/job (Octane safety — prevents stale cost price data).
 */
final class ProductCostPriceFactory
{
    /** @var array<string, float>|null */
    private ?array $costPrices = null;

    public function __construct(
        private readonly StockItemRepositoryInterface $stockItemRepository,
    ) {}

    /**
     * O(1) lookup by SKU. Lazy-loads all cost prices on first call.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getCostPrice(string $sku): ?float
    {
        $this->costPrices ??= $this->stockItemRepository->getCostPricesBySku();

        return $this->costPrices[$sku] ?? null;
    }
}
