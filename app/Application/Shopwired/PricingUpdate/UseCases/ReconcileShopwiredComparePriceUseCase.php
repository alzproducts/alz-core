<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Reconcile ShopWired comparePrice from per-SKU RRP data.
 *
 * Business rule:
 * - Every sellable SKU shares the same non-null RRP → push that RRP as comparePrice
 * - Any null or mismatched RRP across sellable SKUs → clear comparePrice (null)
 *
 * ShopWired stores one comparePrice per product; a uniform RRP is the only
 * value that honestly represents every variant the customer can buy.
 *
 * Always sends the computed value to ShopWired — the local compare_price column
 * is stale (updated by product sync, not by our PUT), so no-op detection is unreliable.
 */
final readonly class ReconcileShopwiredComparePriceUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private ProductUpdateClientInterface $productUpdateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When product not found
     * @throws ResourceNotAvailableException When ShopWired product not found for update
     * @throws InvalidApiRequestException When API request invalid
     * @throws AuthenticationExpiredException When credentials expired
     * @throws ExternalServiceUnavailableException When API or DB unavailable
     * @throws DatabaseOperationFailedException When product lookup fails
     * @throws DuplicateRecordException On constraint violation
     * @throws RecordNotFoundException When product row not found in database
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws MissingRequiredDataException When custom field definitions empty
     */
    public function execute(IntId $productId): void
    {
        $this->logger->info('Starting ShopWired comparePrice reconciliation', [
            'product_id' => $productId->value,
        ]);

        $productView = $this->productRepo->findDetailedProductView($productId, [ProductInclude::Variations]);

        $target = $productView->uniformRrp()?->toGross();

        $this->productUpdateClient->updateComparePrice($productView->id->value, $target);
        $this->logger->info('Reconciled ShopWired comparePrice', [
            'product_id' => $productView->id->value, 'new' => $target,
        ]);
    }
}
