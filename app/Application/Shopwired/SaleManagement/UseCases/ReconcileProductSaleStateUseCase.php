<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\SaleCustomField;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Reconcile a single product's sale state against DB reality.
 *
 * Reads the product from DB, evaluates the specification, and dispatches
 * correction jobs for anything out of sync.
 */
final readonly class ReconcileProductSaleStateUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepo,
        private SaleReconciliationDispatcherInterface $dispatcher,
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private ProductSaleStateResolver $specification,
        private LoggerInterface $logger,
        private int $saleCategoryId,
    ) {}

    /**
     * @throws ResourceNotFoundException When product not found in DB
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function execute(IntId $productId): void
    {
        // Fast-path: check drift without loading the full Product VO
        if (! $this->productRepo->hasSaleStateDrift($productId, $this->saleCategoryId)) {
            $this->logger->debug('No sale state drift detected', [
                'product_id' => $productId->value,
            ]);

            return;
        }

        $product = $this->productRepo->getProduct($productId);
        $result = $this->specification->evaluate($product);

        if (! $result->needsCorrection()) {
            return;
        }

        $this->logger->info('Sale state drift detected, dispatching corrections', [
            'product_id' => $productId->value,
            'needs_add' => $result->needsAddToSale,
            'needs_remove' => $result->needsRemoveFromSale,
            'sku_count' => \count($result->skuSaleStates),
        ]);

        if ($result->needsAddToSale) {
            // Prefer DB-persisted settings; fall back to custom fields for legacy products
            // (added to sale before this feature was deployed — no DB row exists yet).
            $dbSettings = $this->saleSettingsRepo->findByProduct($productId);

            if ($dbSettings === null) {
                $dbSettings = self::buildSaleSettingsFromProduct($product);
                $this->saleSettingsRepo->save($productId, $dbSettings);
            }

            $this->dispatcher->dispatchAddToSale($productId);
        }

        if ($result->needsRemoveFromSale) {
            $this->dispatcher->dispatchRemoveFromSale($productId);
        }

        foreach ($result->skuSaleStates as $skuState) {
            $this->dispatcher->dispatchUpdateSaleState($productId, $skuState->sku);
        }
    }

    /**
     * Reconstruct SaleSettings from a product's existing custom fields.
     *
     * Used in bulk reconciliation when no SaleSettings were provided (the original
     * add-to-sale job has already written custom fields). Falls back to a minimal
     * SaleSettings if custom fields are empty.
     */
    private static function buildSaleSettingsFromProduct(Product $product): SaleSettings
    {
        /** @var array<string, mixed> $raw */
        $raw = $product->rawCustomFields;

        $reason = $raw[SaleCustomField::Reason->value] ?? null;
        if (! \is_string($reason) || $reason === '') {
            return new SaleSettings(saleReason: 'Reconciliation');
        }

        $comments = $raw[SaleCustomField::Comments->value] ?? null;
        $dateStart = $raw[SaleCustomField::DateStart->value] ?? null;
        $dateEnd = $raw[SaleCustomField::DateEnd->value] ?? null;
        $endsStock = $raw[SaleCustomField::EndsStock->value] ?? null;

        return new SaleSettings(
            saleReason: $reason,
            saleComments: \is_string($comments) && $comments !== '' ? $comments : null,
            saleStartDate: self::parseDate($dateStart),
            saleEndDate: self::parseDate($dateEnd),
            saleEndsStock: \is_string($endsStock) && $endsStock !== '' && \is_numeric($endsStock)
                ? (int) $endsStock
                : null,
        );
    }

    /**
     * Parse a string custom field value into a DateTimeImmutable.
     *
     * Returns null for empty/non-string values or unparseable dates.
     */
    private static function parseDate(mixed $value): ?DateTimeImmutable
    {
        if (! \is_string($value) || $value === '') {
            return null;
        }

        $parsed = \date_create_immutable($value);

        return $parsed !== false ? $parsed : null;
    }
}
