<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\Enums\LockName;
use App\Application\Inventory\Results\GenerateVariantSkusResult;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\Resolvers\VariationImageResolver;
use App\Domain\Catalog\Product\Resolvers\VariationPriceResolver;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use Psr\Log\LoggerInterface;

/**
 * Generate Linnworks inventory items for SKU-less ShopWired variations.
 *
 * Creates Linnworks items for each variation that lacks a SKU, using a template
 * item to inherit category and supplier settings. Generated SKUs are written
 * back to ShopWired variations.
 *
 * **Transaction Flow (per variation):**
 * 1. [LOCKED] Generate SKU + create Linnworks item
 * 2. Link supplier from template
 * 3. Add ShopID extended property
 * 4. Add image if available
 * 5. Update ShopWired with new SKU
 * 6. On failure (steps 2-5): delete Linnworks item, continue to next
 *
 * **After all variations:** Refresh local product from ShopWired API.
 */
final readonly class GenerateVariantSkusUseCase
{
    private const int LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private ProductClientInterface $productClient,
        private InventoryClientInterface $inventoryClient,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private BasicProductUpdateClientInterface $shopwiredUpdateClient,
        private ProductSyncService $productSyncService,
        private LockManagerInterface $lockManager,
        private VariationPriceResolver $priceResolver,
        private VariationImageResolver $imageResolver,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute variant SKU generation.
     *
     * @throws ResourceNotFoundException When product or template not found
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When APIs unavailable
     * @throws InvalidApiRequestException When request parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws LockAcquisitionException When SKU generation lock unavailable
     * @throws DatabaseOperationFailedException When local refresh fails
     * @throws DuplicateRecordException When local refresh encounters duplicate
     * @throws InvalidTemplateException When template has no default supplier
     */
    public function execute(GenerateVariantSkusCommand $command): GenerateVariantSkusResult
    {
        $this->logger->info('Starting variant SKU generation', [
            'product_id' => $command->productId->value,
            'template_sku' => $command->templateSku->value,
        ]);

        // 1. Fetch ShopWired product with variations
        $product = $this->productClient->getProductById($command->productId->value);

        if ($product->variations === []) {
            $this->logger->info('Product has no variations', ['product_id' => $command->productId->value]);

            return GenerateVariantSkusResult::noVariations();
        }

        // 2. Fetch Linnworks template item
        $template = $this->inventoryClient->getStockItemFull($command->templateSku);
        $this->validateTemplate($template);

        // 3. Filter to variations without SKUs
        $skuLessVariations = self::filterSkuLessVariations($product->variations);

        if ($skuLessVariations === []) {
            $this->logger->info('All variations already have SKUs', [
                'product_id' => $command->productId->value,
                'total_variations' => \count($product->variations),
            ]);

            return GenerateVariantSkusResult::allSkipped(\count($product->variations));
        }

        // 4. Process each SKU-less variation
        $created = 0;
        $failed = 0;
        /** @var list<string> $createdSkus */
        $createdSkus = [];
        /** @var list<int> $failedVariationIds */
        $failedVariationIds = [];

        foreach ($skuLessVariations as $variation) {
            $result = $this->processVariation($variation, $product, $template);

            if ($result !== null) {
                $created++;
                $createdSkus[] = $result->value;
            } else {
                $failed++;
                $failedVariationIds[] = $variation->id;
            }
        }

        // 5. Refresh local product from API
        $this->productSyncService->refreshById($command->productId->value);

        $this->logger->info('Variant SKU generation completed', [
            'product_id' => $command->productId->value,
            'total' => \count($product->variations),
            'skipped' => \count($product->variations) - \count($skuLessVariations),
            'created' => $created,
            'failed' => $failed,
        ]);

        return new GenerateVariantSkusResult(
            total: \count($product->variations),
            skipped: \count($product->variations) - \count($skuLessVariations),
            created: $created,
            failed: $failed,
            createdSkus: $createdSkus,
            failedVariationIds: $failedVariationIds,
        );
    }

    /**
     * Validate template has required data.
     *
     * @throws InvalidTemplateException When no default supplier
     */
    private function validateTemplate(StockItemFull $template): void
    {
        if ($template->getDefaultSupplier() === null) {
            throw InvalidTemplateException::noDefaultSupplier($template->sku);
        }
    }

    /**
     * Filter to variations that don't have SKUs.
     *
     * @param list<ProductVariation> $variations
     *
     * @return list<ProductVariation>
     */
    private static function filterSkuLessVariations(array $variations): array
    {
        return \array_values(\array_filter(
            $variations,
            static fn(ProductVariation $v): bool => $v->sku === null,
        ));
    }

    /**
     * Process a single variation: create in Linnworks, update ShopWired.
     *
     * @return Sku|null The created SKU, or null on failure
     *
     * @throws LockAcquisitionException When SKU generation lock unavailable
     */
    private function processVariation(
        ProductVariation $variation,
        Product $product,
        StockItemFull $template,
    ): ?Sku {
        $this->logger->debug('Processing variation', [
            'variation_id' => $variation->id,
            'options' => $variation->optionValuesString(),
        ]);

        $stockItemId = null;

        try {
            // LOCKED: Generate SKU and create item
            [$sku, $stockItemId] = $this->lockManager->withLock(
                LockName::SkuGeneration->value,
                self::LOCK_TIMEOUT_SECONDS,
                function () use ($variation, $product, $template): array {
                    $sku = $this->inventoryClient->getNewItemNumber();

                    $stockItemId = $this->inventoryUpdateClient->addInventoryItem(
                        Guid::fromTrusted($template->categoryId),
                        $this->buildAddItemCommand($sku, $variation, $product),
                    );

                    return [$sku, $stockItemId];
                },
            );

            // Outside lock: Link supplier, add EP, add image, update ShopWired
            $this->completeItemSetup($stockItemId, $variation, $product, $template);
            $this->updateShopWiredVariation($variation, $sku);

            $this->logger->info('Variation processed successfully', [
                'variation_id' => $variation->id,
                'sku' => $sku->value,
            ]);

            return $sku;
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            $this->logger->error('Failed to process variation', [
                'variation_id' => $variation->id,
                'error' => $e->getMessage(),
            ]);

            // Attempt rollback if we have a stockItemId
            if ($stockItemId !== null) {
                $this->attemptRollback($stockItemId, $variation->id);
            }

            return null;
        }
    }

    /**
     * Build the AddInventoryItemCommand for a variation.
     */
    private function buildAddItemCommand(
        Sku $sku,
        ProductVariation $variation,
        Product $product,
    ): AddInventoryItemCommand {
        $prices = $this->priceResolver->resolveFromProduct($variation, $product);
        $taxRate = $product->vatExclusive ? TaxRate::zero() : TaxRate::standard();

        // Build title: "Product Name - Option Values"
        $optionValues = $variation->optionValuesString();
        $title = $optionValues !== ''
            ? $product->title . ' - ' . $optionValues
            : $product->title;

        // Build Money based on tax treatment
        $retailPrice = $product->vatExclusive
            ? Money::zeroRated($prices->price)
            : Money::inclusive($prices->price);

        // Cost price can be null (unknown)
        $purchasePrice = $prices->costPrice !== null
            ? Money::exclusive($prices->costPrice)
            : null;

        return new AddInventoryItemCommand(
            sku: $sku,
            title: $title,
            retailPrice: $retailPrice,
            purchasePrice: $purchasePrice,
            taxRate: $taxRate,
            barcode: $variation->gtin,
            mpn: $variation->mpn,
        );
    }

    /**
     * Complete item setup: supplier, extended property, image.
     *
     * @throws ResourceNotFoundException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    private function completeItemSetup(
        Guid $stockItemId,
        ProductVariation $variation,
        Product $product,
        StockItemFull $template,
    ): void {
        $supplier = $template->getDefaultSupplier();
        \assert($supplier !== null); // Validated in validateTemplate()

        $prices = $this->priceResolver->resolveFromProduct($variation, $product);

        // Cost price can be null (unknown)
        $purchasePrice = $prices->costPrice !== null
            ? Money::exclusive($prices->costPrice)
            : null;

        // Link supplier
        $this->inventoryUpdateClient->createSupplierStat(
            identifier: $stockItemId,
            supplierId: Guid::fromTrusted($supplier->supplierId),
            purchasePrice: $purchasePrice,
            supplierCode: $supplier->code,
            isDefault: true,
        );

        // Add ShopID extended property
        $this->inventoryUpdateClient->addExtendedProperty(
            identifier: $stockItemId,
            name: ExtendedPropertyName::ShopId->value,
            value: (string) $variation->id,
        );

        // Add image if available
        $imageUrl = $this->imageResolver->resolveUrl($variation, $product->images);
        if ($imageUrl !== null) {
            $this->inventoryUpdateClient->addImage($stockItemId, $imageUrl);
        }
    }

    /**
     * Update ShopWired variation with the new SKU.
     *
     * @throws ResourceNotFoundException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    private function updateShopWiredVariation(ProductVariation $variation, Sku $sku): void
    {
        $this->shopwiredUpdateClient->update(new UpdateBasicProductCommand(
            identifier: IntId::from($variation->id),
            newSku: $sku,
        ));
    }

    /**
     * Attempt to delete Linnworks item on failure.
     */
    private function attemptRollback(Guid $stockItemId, int $variationId): void
    {
        try {
            $this->inventoryUpdateClient->deleteInventoryItem($stockItemId);
            $this->logger->info('Rolled back Linnworks item', [
                'stock_item_id' => $stockItemId->value,
                'variation_id' => $variationId,
            ]);
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            // Critical: orphaned item in Linnworks
            $this->logger->critical('Failed to rollback Linnworks item - manual cleanup required', [
                'stock_item_id' => $stockItemId->value,
                'variation_id' => $variationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
