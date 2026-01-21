<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Factories;

use App\Domain\Catalog\Product\Exceptions\MissingVariationSkuException;
use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\InvalidGtinException;
use App\Infrastructure\Shopwired\Responses\ProductImageResponse;
use App\Infrastructure\Shopwired\Responses\ProductResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationOptionResponse;
use App\Infrastructure\Shopwired\Responses\ProductVariationResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

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
            sku: $response->sku,
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
            createdAt: CarbonImmutable::parse($response->createdAt)->toDateTimeImmutable(),
            updatedAt: CarbonImmutable::parse($response->updatedAt)->toDateTimeImmutable(),
        );
    }

    /**
     * Build variations, logging and skipping any with missing SKUs.
     *
     * @param list<ProductVariationResponse> $variations
     *
     * @return list<ProductVariation>
     */
    private function buildVariations(int $productExternalId, array $variations): array
    {
        $result = [];

        foreach ($variations as $variation) {
            try {
                $result[] = $variation->toDomain($productExternalId);
            } catch (MissingVariationSkuException $e) {
                Log::error('Skipping product variation with missing SKU - fix in ShopWired admin', [
                    'variation_id' => $e->variationId,
                    'product_external_id' => $e->productExternalId,
                    'options' => $this->buildOptionsDisplayString($variation),
                ]);
            }
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
        if ($gtin === null || $gtin === '') {
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
