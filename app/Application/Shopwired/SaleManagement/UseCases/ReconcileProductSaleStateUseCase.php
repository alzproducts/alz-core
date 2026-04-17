<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\UseCases;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Contracts\Shopwired\SaleReconciliationDispatcherInterface;
use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Application\Shopwired\SaleManagement\Resolvers\ProductSaleStateResolver;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductInclude;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
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

        $view = $this->productRepo->findDetailedProductView($productId, [ProductInclude::CustomFields]);
        $result = $this->specification->evaluate($view);

        if (! $result->needsCorrection()) {
            $this->logger->debug('Drift resolved before correction — no action needed', [
                'product_id' => $productId->value,
            ]);

            return;
        }

        $this->logger->info('Sale state drift detected, dispatching corrections', [
            'product_id' => $productId->value,
            'needs_add' => $result->needsAddToSale,
            'needs_remove' => $result->needsRemoveFromSale,
        ]);

        if ($result->needsAddToSale) {
            // Prefer DB-persisted settings; fall back to custom fields for legacy products
            // (added to sale before this feature was deployed — no DB row exists yet).
            $dbSettings = $this->saleSettingsRepo->findByProduct($productId);

            if ($dbSettings === null) {
                $dbSettings = self::buildSaleSettingsFromView($view);
                $this->saleSettingsRepo->save($productId, $dbSettings);
            }

            $this->dispatcher->dispatchAddToSale($productId);
        }

        if ($result->needsRemoveFromSale) {
            $this->dispatcher->dispatchRemoveFromSale($productId);
        }
    }

    /**
     * Reconstruct SaleSettings from a ProductView's typed custom fields.
     *
     * Falls back to a minimal SaleSettings with 'Reconciliation' reason
     * when custom fields have no sale_reason.
     */
    private static function buildSaleSettingsFromView(ProductView $view): SaleSettings
    {
        return SaleSettings::fromTypedCustomFields($view->customFields)
            ?? new SaleSettings(saleReason: 'Reconciliation');
    }
}
