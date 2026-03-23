<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Data\InvalidGtinException;
use App\Infrastructure\Shopwired\Responses\ProductImageResponse;
use App\Infrastructure\Shopwired\Responses\ProductResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationOptionResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationResponse;
use App\Infrastructure\Shopwired\Responses\ProductWebhookResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Optional;

/**
 * Factory for creating Product domain objects from API responses (write path).
 *
 * Handles:
 * - GTIN validation with graceful degradation (invalid GTINs logged, treated as null)
 * - Variation error handling (missing SKUs logged, variation skipped)
 *
 * **Read path**: Use ProductModelMapper for converting DB models to domain objects.
 *
 * @see Product
 */
final class ProductDomainFactory
{
    /**
     * Create a Product domain object from an API response.
     *
     * Stores raw custom fields for persistence, skips typed interpretation.
     * Custom field typing happens on the read path via ProductModelMapper.
     */
    public function fromResponse(ProductResponse $response): Product
    {
        return new Product(
            id: $response->id,
            sku: $response->sku === '' ? null : $response->sku,
            gtin: $this->buildGtin($response->gtin, $response->id),
            title: $response->title,
            description: $response->description,
            slug: $response->slug,
            url: $response->url,
            price: $response->price,
            costPrice: $response->costPrice,
            salePrice: $response->salePrice,
            comparePrice: $response->comparePrice,
            stock: $response->stock,
            isActive: $response->isActive,
            vatExclusive: $response->vatExclusive,
            vatRelief: $response->vatRelief,
            weight: $response->weight,
            metaTitle: $response->metaTitle,
            metaDescription: $response->metaDescription,
            categoryIds: $response->getCategoryIds(),
            variations: $this->buildVariations($response->id, $response->variations),
            images: $this->buildImages($response->images),
            rawCustomFields: $response->customFields,
            customFields: [],
            rawFilters: $response->filters,
            filters: [],
            sortOrder: $response->sortOrder,
            createdAt: CarbonImmutable::parse($response->createdAt)->toDateTimeImmutable(),
            updatedAt: CarbonImmutable::parse($response->updatedAt)->toDateTimeImmutable(),
        );
    }

    /**
     * Create a Product domain object from a webhook response.
     *
     * Handles Optional embed fields by substituting safe defaults.
     * The defaults are only used to satisfy the Product constructor — embed-dependent
     * columns are excluded from the DB upsert by the mapper when not present.
     */
    public function fromWebhookResponse(ProductWebhookResponse $response): Product
    {
        return new Product(
            id: $response->id,
            sku: $response->sku === '' ? null : $response->sku,
            gtin: $this->buildGtin($response->gtin, $response->id),
            title: $response->title,
            description: $response->description,
            slug: $response->slug,
            url: $response->url,
            price: $response->price,
            costPrice: $response->costPrice,
            salePrice: $response->salePrice,
            comparePrice: $response->comparePrice,
            stock: $response->stock,
            isActive: $response->isActive,
            vatExclusive: $response->vatExclusive,
            vatRelief: $response->vatRelief instanceof Optional ? false : $response->vatRelief,
            weight: $response->weight,
            metaTitle: $response->metaTitle,
            metaDescription: $response->metaDescription,
            categoryIds: $response->getCategoryIds(),
            variations: $response->variations instanceof Optional
                ? []
                : $this->buildVariations($response->id, $response->variations),
            images: $response->images instanceof Optional
                ? []
                : $this->buildImages($response->images),
            rawCustomFields: $response->customFields instanceof Optional ? [] : $response->customFields,
            customFields: [],
            rawFilters: $response->filters instanceof Optional ? [] : $response->filters,
            filters: [],
            sortOrder: $response->sortOrder,
            createdAt: CarbonImmutable::parse($response->createdAt)->toDateTimeImmutable(),
            updatedAt: CarbonImmutable::parse($response->updatedAt)->toDateTimeImmutable(),
        );
    }

    /**
     * Build variations, logging any with missing SKUs.
     *
     * @param list<ProductVariationResponse> $variations
     *
     * @return list<ProductVariation>
     */
    private function buildVariations(int $productExternalId, array $variations): array
    {
        $result = [];

        foreach ($variations as $variation) {
            $domainVariation = $variation->toDomain($productExternalId);

            // Log missing SKUs as notice (data quality issue, not blocking)
            if ($domainVariation->sku === null) {
                Log::notice('Product variation has missing SKU - consider fixing in ShopWired admin', [
                    'variation_id' => $domainVariation->id,
                    'product_external_id' => $productExternalId,
                    'options' => $this->buildOptionsDisplayString($variation),
                ]);
            }

            $result[] = $domainVariation;
        }

        return $result;
    }

    /**
     * Build a display string from variation options (e.g., "Size: Large, Color: Red").
     */
    private function buildOptionsDisplayString(ProductVariationResponse $variation): string
    {
        if ($variation->values === []) {
            return '(no options)';
        }

        return \implode(', ', \array_map(
            static fn(ProductVariationOptionResponse $opt): string => "{$opt->option['name']}: {$opt->name}",
            $variation->values,
        ));
    }

    /**
     * @param list<ProductImageResponse> $images
     *
     * @return list<ProductImage>
     */
    private function buildImages(array $images): array
    {
        return \array_map(
            static fn(ProductImageResponse $img): ProductImage => $img->toDomain(),
            $images,
        );
    }

    /**
     * Build a GTIN value object from a raw string, logging invalid values.
     *
     * Invalid GTINs are logged and treated as null (product continues sync).
     */
    private function buildGtin(?string $gtin, int $productExternalId): ?Gtin
    {
        // 'Does not apply' is a common placeholder in ShopWired for products without GTINs
        if ($gtin === null || $gtin === '' || $gtin === 'Does not apply') {
            return null;
        }

        try {
            return Gtin::fromString($gtin);
        } catch (InvalidGtinException $e) {
            Log::warning('Invalid GTIN in product - fix in ShopWired admin', [
                'product_external_id' => $productExternalId,
                'gtin' => $gtin,
                'reason' => $e->reason,
            ]);

            return null;
        }
    }
}
