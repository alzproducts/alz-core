<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate;

use App\Application\Contracts\Shopwired\SaleSettingsRepositoryInterface;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Catalog\Product\ValueObjects\SaleSubmissionContext;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Manages sale settings persistence around price updates.
 *
 * Handles the decision logic for when to save, preserve, or delete
 * product_sale_settings based on the type of price update being performed.
 */
final readonly class SaleStatePersistenceService
{
    public function __construct(
        private SaleSettingsRepositoryInterface $saleSettingsRepo,
        private LoggerInterface $logger,
    ) {}

    /**
     * Persist or clear sale settings before the API call.
     *
     * @param list<UpdatePriceCommand> $skuUpdates Commands being processed
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function persistSaleState(
        IntId $productId,
        ?SaleSettings $saleSettings,
        Product $product,
        array $skuUpdates,
    ): ?SaleSubmissionContext {
        if ($saleSettings?->removalReason !== null) {
            return $this->handleSaleRemoval($productId, $saleSettings->removalReason, $product, $skuUpdates);
        }

        if ($saleSettings !== null) {
            $this->handleSaleAddition($productId, $saleSettings);
        }

        return null;
    }

    /**
     * @param list<UpdatePriceCommand> $skuUpdates
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function handleSaleRemoval(
        IntId $productId,
        SaleRemovalReason $reason,
        Product $product,
        array $skuUpdates,
    ): SaleSubmissionContext {
        $existingSettings = $this->saleSettingsRepo->findByProduct($productId);
        $context = $existingSettings !== null
            ? SaleSubmissionContext::fromSaleSettings($existingSettings, $reason)
            : new SaleSubmissionContext(removalReason: $reason);
        if (self::allOnSaleSkusBeingRemoved($product, $skuUpdates)) {
            $this->saleSettingsRepo->delete($productId);
            $this->logger->info('Deleted sale settings — all on-sale SKUs removed', ['product_id' => $productId->value]);
        } else {
            $this->logger->info('Preserving sale settings — other SKUs remain on sale', ['product_id' => $productId->value]);
        }

        return $context;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function handleSaleAddition(IntId $productId, SaleSettings $saleSettings): void
    {
        $this->saleSettingsRepo->save($productId, $saleSettings);
        $this->logger->info('Persisted sale settings', ['product_id' => $productId->value]);
    }

    /** @param list<UpdatePriceCommand> $skuUpdates */
    private static function allOnSaleSkusBeingRemoved(Product $product, array $skuUpdates): bool
    {
        $onSaleSkus = $product->allOnSaleSkus();
        if ($onSaleSkus === []) {
            return true;
        }

        $removals = [];
        foreach ($skuUpdates as $cmd) {
            if ($cmd->salePrice !== null && $cmd->salePrice->isZero()) {
                $removals[$cmd->sku->value] = true;
            }
        }

        return \array_all($onSaleSkus, static fn(Sku $sku): bool => isset($removals[$sku->value]));
    }
}
