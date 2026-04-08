<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Exceptions\RequiredRelationNotLoadedException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
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
 * - Uniform selling price → set comparePrice to highest RRP across all SKUs
 * - Non-uniform prices → clear comparePrice (null/0)
 * - No RRP data → clear comparePrice
 *
 * Only updates ShopWired when the target differs from the current value.
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
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws MissingRequiredDataException When custom field definitions empty
     * @throws RequiredRelationNotLoadedException When required relations not loaded (programming error)
     */
    public function execute(IntId $productId): void
    {
        $product = $this->productRepo->getProduct($productId);

        $targetComparePrice = $product->hasSingleSellingPrice()
            ? $product->resolveHighestRrp()
            : null;

        if (self::comparePricesEqual($targetComparePrice, $product->comparePrice)) {
            return;
        }

        $this->productUpdateClient->updateComparePrice($product->id, $targetComparePrice);

        $this->logger->info('Reconciled ShopWired comparePrice', [
            'product_id' => $product->id,
            'previous' => $product->comparePrice,
            'new' => $targetComparePrice,
        ]);
    }

    /** Floating-point-safe comparison — both null means equal, otherwise within 0.001. */
    private static function comparePricesEqual(?float $a, ?float $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }

        if ($a === null || $b === null) {
            return false;
        }

        return \abs($a - $b) < 0.001;
    }
}
