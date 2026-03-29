<?php

declare(strict_types=1);

namespace App\Application\Operations\UseCases;

use App\Application\Contracts\Operations\PricePeriodRepositoryInterface;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Operations\ValueObjects\PriceSnapshot;

/**
 * Record a price period change in the SCD2 price history table.
 *
 * Decomposes the domain pricing VO into scalar values for the repository,
 * keeping the persistence boundary clean of domain types.
 */
final readonly class RecordPricePeriodUseCase
{
    public function __construct(
        private PricePeriodRepositoryInterface $repo,
    ) {}

    /**
     * @throws DatabaseOperationFailedException On permanent database failure
     * @throws DuplicateRecordException On unique constraint violation
     * @throws ExternalServiceUnavailableException On transient database failure (retryable)
     */
    public function execute(Sku $sku, ProductRetailPricing $newPrices): void
    {
        $this->repo->recordPriceChange(new PriceSnapshot(
            sku: $sku,
            basePriceGross: $newPrices->basePrice->toGross(),
            salePriceGross: $newPrices->salePrice?->toGross(),
            effectivePriceGross: $newPrices->effectivePrice()->toGross(),
            priceHasTax: $newPrices->taxType()->hasTax(),
        ));
    }
}
