<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\PricingUpdate\UseCases\UpdateProductSellingPricesUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\CustomFields\ValueObjects\StringCustomFieldValue;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
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
 * For each match, calls UpdateProductSellingPricesUseCase with salePrice=0 and
 * the appropriate SaleRemovalReason. The standard event chain handles
 * all downstream side-effects (ShopWired, Linnworks, Slack).
 */
final readonly class CheckExpiredSalesUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private UpdateProductSellingPricesUseCase $updatePricesUseCase,
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
        $views = $this->productRepository->findProductViewsOnSale();
        $removed = 0;
        $failed = 0;
        $today = new DateTimeImmutable('today');

        foreach ($views as $view) {
            $reason = $this->evaluateRemovalConditions($view, $today);

            if ($reason === null) {
                continue;
            }

            $onSaleSkus = $view->allOnSaleSkus();
            if ($onSaleSkus === []) {
                $this->logger->warning('Cannot auto-remove sale: product has no on-sale SKUs', [
                    'product_id' => $view->id->value,
                    'reason' => $reason->value,
                ]);
                $failed++;

                continue;
            }

            try {
                $this->removeSale($onSaleSkus, $reason);
                $removed++;

                $this->logger->info('Auto-removed product from sale', [
                    'product_id' => $view->id->value,
                    'skus' => \array_map(static fn(Sku $s): string => $s->value, $onSaleSkus),
                    'reason' => $reason->value,
                ]);
            } catch (Exception $e) { // @ignoreException — batch processing: continue on individual failures
                $failed++;

                $this->logger->error('Failed to auto-remove product from sale', [
                    'product_id' => $view->id->value,
                    'skus' => \array_map(static fn(Sku $s): string => $s->value, $onSaleSkus),
                    'reason' => $reason->value,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Expired sales check completed', [
            'checked' => \count($views),
            'removed' => $removed,
            'failed' => $failed,
        ]);

        return [
            'checked' => \count($views),
            'removed' => $removed,
            'failed' => $failed,
        ];
    }

    private function evaluateRemovalConditions(ProductView $view, DateTimeImmutable $today): ?SaleRemovalReason
    {
        if (! $view->isActive) {
            return SaleRemovalReason::ProductInactive;
        }

        if ($this->isSaleEndDateReached($view, $today)) {
            return SaleRemovalReason::EndDateReached;
        }

        if ($view->stockLevel->availableStock <= 0 && $view->hasCustomField('discontinued')) {
            return SaleRemovalReason::OutOfStockDiscontinued;
        }

        return $this->evaluateStockThreshold($view);
    }

    private function isSaleEndDateReached(ProductView $view, DateTimeImmutable $today): bool
    {
        $field = $view->getCustomField(SaleCustomField::DateEnd->value);

        if (! $field instanceof StringCustomFieldValue || $field->value === '') {
            return false;
        }

        try {
            return new DateTimeImmutable($field->value) <= $today;
        } catch (DateMalformedStringException) { // @ignoreException — invalid date format in custom field, skip condition
            return false;
        }
    }

    private function evaluateStockThreshold(ProductView $view): ?SaleRemovalReason
    {
        $field = $view->getCustomField(SaleCustomField::EndsStock->value);

        if (! $field instanceof StringCustomFieldValue || ! \is_numeric($field->value)) {
            return null;
        }

        return $view->stockLevel->availableStock <= (int) $field->value
            ? SaleRemovalReason::SaleUnitsSold
            : null;
    }

    /**
     * Clear sale prices for all on-sale SKUs in a single batch API call.
     *
     * @param list<Sku> $skus All SKUs with active sale prices
     *
     * @throws ResourceNotFoundException When product not found for price update
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When local product lookup fails
     * @throws DuplicateRecordException On sale settings DB constraint violation
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws ValidationFailedException When price fails VAT round-trip check
     */
    private function removeSale(array $skus, SaleRemovalReason $reason): void
    {
        $commands = \array_map(
            static fn(Sku $sku): UpdatePriceCommand => new UpdatePriceCommand(
                sku: $sku,
                salePrice: Money::inclusive(0),
            ),
            $skus,
        );

        $this->updatePricesUseCase->execute(
            skuUpdates: $commands,
            saleSettings: SaleSettings::forRemoval($reason),
        );
    }
}
