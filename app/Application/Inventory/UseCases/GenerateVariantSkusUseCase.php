<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Inventory\Commands\GenerateVariantSkusCommand;
use App\Application\Inventory\Results\GenerateVariantSkusResult;
use App\Application\Inventory\Services\GenerateStockItemFromVariationService;
use App\Application\Inventory\Services\StockItemParamsBuilderService;
use App\Application\Shopwired\Services\ProductSyncService;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
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
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Generate Linnworks inventory items for SKU-less ShopWired variations.
 *
 * Creates Linnworks items for each variation that lacks a SKU, using a template
 * item to inherit category and supplier settings. Generated SKUs are written
 * back to ShopWired variations.
 *
 * **Transaction Flow (per variation):**
 * 1. Build CreateStockItemParams via StockItemParamsBuilderService
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
        private StockItemParamsBuilderService $paramsBuilder,
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
        private int $standardSignProductId,
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
     * @throws InvalidCustomFieldValueException When product custom fields invalid
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

        // 4. If standard sign mode, load reference product for price matching
        $standardSignVariations = $command->isStandardSign
            ? $this->loadStandardSignVariations()
            : null;

        // 5. Process each SKU-less variation
        $created = 0;
        $failed = 0;
        /** @var list<string> $createdVariants */
        $createdVariants = [];
        /** @var list<int> $failedVariationIds */
        $failedVariationIds = [];

        foreach ($skuLessVariations as $variation) {
            $result = $this->processVariation($variation, $product, $template, $command, $standardSignVariations);

            if ($result !== null) {
                $created++;
                $optionValues = $variation->optionValuesString();
                $createdVariants[] = $optionValues !== ''
                    ? "{$result->value} - {$optionValues}"
                    : $result->value;
            } else {
                $failed++;
                $failedVariationIds[] = $variation->id;
            }
        }

        // 6. Refresh local product from API
        $this->productSyncService->refreshById($command->productId->value);

        $this->logger->info('Variant SKU generation completed', [
            'product_id' => $command->productId->value,
            'total' => \count($product->variations),
            'skipped' => \count($product->variations) - \count($skuLessVariations),
            'created' => $created,
            'failed' => $failed,
        ]);

        $result = new GenerateVariantSkusResult(
            total: \count($product->variations),
            skipped: \count($product->variations) - \count($skuLessVariations),
            created: $created,
            failed: $failed,
            productTitle: $product->title,
            createdVariants: $createdVariants,
            failedVariationIds: $failedVariationIds,
        );

        $this->dispatchNotificationEvent($result, $command);

        return $result;
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
     * Load standard sign product variations for price matching.
     *
     * @return list<ProductVariation>
     *
     * @throws ResourceNotFoundException When standard sign product not found in local DB
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws DatabaseOperationFailedException When local DB query fails
     * @throws InvalidCustomFieldValueException When product custom fields invalid
     */
    private function loadStandardSignVariations(): array
    {
        $standardProduct = $this->productRepository->getProduct(IntId::from($this->standardSignProductId));

        $this->logger->info('Loaded standard sign product for price matching', [
            'product_id' => $standardProduct->id,
            'variation_count' => \count($standardProduct->variations),
        ]);

        return $standardProduct->variations;
    }

    /**
     * Dispatch Slack notification event when SKUs were created.
     */
    private function dispatchNotificationEvent(GenerateVariantSkusResult $result, GenerateVariantSkusCommand $command): void
    {
        if ($result->created > 0) {
            \event(new VariantSkusGeneratedEvent(
                productId: $command->productId->value,
                productTitle: $result->productTitle,
                created: $result->created,
                skipped: $result->skipped,
                failed: $result->failed,
                createdVariants: $result->createdVariants,
            ));
        }
    }

    /**
     * Process a single variation: create in Linnworks, update ShopWired.
     *
     * @param list<ProductVariation>|null $standardSignVariations Reference variations for price matching
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
        ?array $standardSignVariations,
    ): ?Sku {
        $this->logger->debug('Processing variation', [
            'variation_id' => $variation->id,
            'options' => $variation->optionValuesString(),
        ]);

        try {
            // Build params from variation data
            $params = $this->paramsBuilder->build($variation, $product, $template, $command, $standardSignVariations);

            // Delegate to service (handles Linnworks creation, ShopWired update, rollback)
            $sku = $this->stockItemGenerator->generate($params, $variation->id);

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
}
