<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Application\Inventory\Results\GenerateVariantSkusResult;
use App\Application\Inventory\Services\GenerateStockItemFromVariationService;
use App\Application\Shopwired\Services\ProductSyncService;
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
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Generate Linnworks inventory items for SKU-less ShopWired variations.
 *
 * Creates Linnworks items for each variation that lacks a SKU, using a template
 * item to inherit category and supplier settings. Generated SKUs are written
 * back to ShopWired variations.
 *
 * **Transaction Flow (per variation):**
 * 1. Build CreateStockItemParams from variation data
 * 2. Delegate to GenerateStockItemFromVariationService (handles Linnworks creation, ShopWired update, rollback)
 * 3. On failure: continue to next variation
 *
 * **After all variations:** Refresh local product from ShopWired API.
 */
final readonly class GenerateVariantSkusUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private ProductSyncService $productSyncService,
        private GenerateStockItemFromVariationService $stockItemGenerator,
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
            'copy_parent_mpn' => $command->copyParentMpn,
            'no_supplier' => $command->noSupplier,
            'is_standard_sign' => $command->isStandardSign,
        ]);

        // 1. Fetch ShopWired product and sync to local DB (so variation lookups work)
        $product = $this->productSyncService->refreshById($command->productId->value);

        if ($product->variations === []) {
            $this->logger->info('Product has no variations', ['product_id' => $command->productId->value]);

            return GenerateVariantSkusResult::noVariations($product->title);
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

            return GenerateVariantSkusResult::allSkipped(\count($product->variations), $product->title);
        }

        // 4. Process each SKU-less variation
        $created = 0;
        $failed = 0;
        /** @var list<string> $createdSkus */
        $createdSkus = [];
        /** @var list<int> $failedVariationIds */
        $failedVariationIds = [];

        foreach ($skuLessVariations as $variation) {
            $result = $this->processVariation($variation, $product, $template, $command);

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
            productTitle: $product->title,
            createdSkus: $createdSkus,
            failedVariationIds: $failedVariationIds,
        );
    }

    /**
     * Validate template has required data.
     *
     * @throws InvalidTemplateException When template has no default supplier
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
        GenerateVariantSkusCommand $command,
    ): ?Sku {
        $this->logger->debug('Processing variation', [
            'variation_id' => $variation->id,
            'options' => $variation->optionValuesString(),
        ]);

        try {
            // Build params from variation data
            $params = $this->buildCreateParams($variation, $product, $template, $command);

            // Delegate to service (handles Linnworks creation, ShopWired update, rollback)
            $sku = $this->stockItemGenerator->generate($params, $variation->id, $command->noSupplier);

            $this->logger->info('Variation processed successfully', [
                'variation_id' => $variation->id,
                'sku' => $sku->value,
            ]);

            return $sku;
            // Note: LockAcquisitionException intentionally bubbles up - it indicates infrastructure
            // problems (Redis down, stuck lock) that would affect ALL variations. Fail-fast is correct.
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            $this->logger->error('Failed to process variation', [
                'variation_id' => $variation->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build CreateStockItemParams from variation, product, and template.
     */
    private function buildCreateParams(
        ProductVariation $variation,
        Product $product,
        StockItemFull $template,
        GenerateVariantSkusCommand $command,
    ): CreateStockItemParams {
        // Template is always validated to have a default supplier
        $supplier = $template->getDefaultSupplier();
        Assert::notNull($supplier, 'Template must have a default supplier (validated in validateTemplate)');

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

        // Resolve purchase price from variation cost
        $purchasePrice = $prices->costPrice !== null
            ? Money::exclusive($prices->costPrice)
            : null;

        // Resolve MPN: template supplier code (--copy-mpn) or variation MPN
        $mpn = $command->copyParentMpn
            ? $supplier->code
            : $variation->mpn;

        // Resolve image URL
        $imageUrl = $this->imageResolver->resolveUrl($variation, $product->images);

        return new CreateStockItemParams(
            categoryId: Guid::fromTrusted($template->categoryId),
            title: $title,
            retailPrice: $retailPrice,
            taxRate: $taxRate,
            supplierId: Guid::fromTrusted($supplier->supplierId),
            purchasePrice: $command->noSupplier ? null : $purchasePrice,
            barcode: $variation->gtin,
            mpn: $mpn,
            supplierCode: $command->noSupplier ? null : $supplier->code,
            extendedProperties: [
                ExtendedPropertyName::ShopId->value => (string) $variation->id,
            ],
            imageUrl: $imageUrl,
        );
    }
}
