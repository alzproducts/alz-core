<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductPricesUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateMalformedStringException;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Check for products that should be automatically removed from sale.
 *
 * Evaluates 4 removal conditions against local DB data:
 * 1. Product inactive
 * 2. Sale end date passed
 * 3. Out of stock + discontinued
 * 4. Sale units sold (stock <= threshold)
 *
 * For each match, calls UpdateProductPricesUseCase with salePrice=0 and
 * the appropriate SaleRemovalReason. The standard event chain handles
 * all downstream side-effects (ShopWired, Linnworks, Slack).
 */
final readonly class CheckExpiredSalesUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private UpdateProductPricesUseCase $updatePricesUseCase,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{checked: int, removed: int, failed: int}
     *
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException When product query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(): array
    {
        $products = $this->productRepository->getProductsOnSale();
        $removed = 0;
        $failed = 0;
        $today = new DateTimeImmutable('today');

        foreach ($products as $product) {
            $reason = $this->evaluateRemovalConditions($product, $today);

            if ($reason === null) {
                continue;
            }

            if ($product->sku === null || $product->sku === '') {
                $this->logger->warning('Cannot auto-remove sale: product has no SKU', [
                    'product_id' => $product->id,
                    'reason' => $reason->value,
                ]);
                $failed++;

                continue;
            }

            try {
                $this->removeSale($product->sku, $reason);
                $removed++;

                $this->logger->info('Auto-removed product from sale', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'reason' => $reason->value,
                ]);
            } catch (Exception $e) { // @ignoreException — batch processing: continue on individual failures
                $failed++;

                $this->logger->error('Failed to auto-remove product from sale', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'reason' => $reason->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Expired sales check completed', [
            'checked' => \count($products),
            'removed' => $removed,
            'failed' => $failed,
        ]);

        return [
            'checked' => \count($products),
            'removed' => $removed,
            'failed' => $failed,
        ];
    }

    private function evaluateRemovalConditions(Product $product, DateTimeImmutable $today): ?SaleRemovalReason
    {
        if (! $product->isActive) {
            return SaleRemovalReason::ProductInactive;
        }

        if ($this->isSaleEndDateReached($product, $today)) {
            return SaleRemovalReason::EndDateReached;
        }

        if ($product->totalStock() <= 0 && $this->getRawCustomFieldString($product, 'discontinued') !== null) {
            return SaleRemovalReason::OutOfStockDiscontinued;
        }

        return $this->evaluateStockThreshold($product);
    }

    private function isSaleEndDateReached(Product $product, DateTimeImmutable $today): bool
    {
        $saleEndDate = $this->getRawCustomFieldString($product, SaleCustomField::DateEnd->value);

        if ($saleEndDate === null) {
            return false;
        }

        try {
            return new DateTimeImmutable($saleEndDate) <= $today;
        } catch (DateMalformedStringException) { // @ignoreException — invalid date format in custom field, skip condition
            return false;
        }
    }

    private function evaluateStockThreshold(Product $product): ?SaleRemovalReason
    {
        $saleEndsStock = $this->getRawCustomFieldString($product, SaleCustomField::EndsStock->value);

        if ($saleEndsStock === null || ! \is_numeric($saleEndsStock)) {
            return null;
        }

        return $product->totalStock() <= (int) $saleEndsStock
            ? SaleRemovalReason::SaleUnitsSold
            : null;
    }

    /**
     * @throws ResourceNotFoundException When product not found for price update
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     */
    private function removeSale(string $sku, SaleRemovalReason $reason): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted($sku),
            salePrice: Money::inclusive(0),
        );

        $this->updatePricesUseCase->execute(
            skuUpdates: [$command],
            saleSettings: SaleSettings::forRemoval($reason),
        );
    }

    /**
     * Get a non-empty string custom field value, or null if absent/empty.
     */
    private function getRawCustomFieldString(Product $product, string $name): ?string
    {
        $value = $product->rawCustomFields[$name] ?? null;

        if (! \is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
