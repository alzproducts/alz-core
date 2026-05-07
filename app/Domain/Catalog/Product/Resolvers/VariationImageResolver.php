<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Resolvers;

use App\Domain\Catalog\Product\ValueObjects\ProductImage;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;

/**
 * Resolves a variation's image from the parent product's image list.
 *
 * Variations in ShopWired store an `imageIndex` that points to a specific image
 * in the parent product's images array. This resolver handles the lookup safely,
 * returning null when:
 * - The variation has no imageIndex (null)
 * - The parent has no images
 * - The imageIndex is out of bounds
 *
 * **Important:** ShopWired stores imageIndex as 1-based (matching UI display),
 * so we subtract 1 to convert to 0-based array index.
 *
 * **Usage:**
 * ```php
 * $resolver = new VariationImageResolver();
 * $image = $resolver->resolve($variation, $product->images);
 * if ($image !== null) {
 *     $imageUrl = $image->url;
 * }
 * ```
 *
 * @template-pattern Domain Service
 */
final readonly class VariationImageResolver
{
    /**
     * Resolve the variation's image from parent images.
     *
     * @param ProductVariation $variation The variation to resolve image for
     * @param list<ProductImage> $parentImages Parent product's images array
     *
     * @return ProductImage|null The resolved image, or null if none available
     */
    public function resolve(ProductVariation $variation, array $parentImages): ?ProductImage
    {
        return OneBasedIndexLookup::at($variation->imageIndex, $parentImages);
    }

    /**
     * Resolve the variation's image URL directly.
     *
     * Convenience method when you only need the URL string.
     *
     * @param ProductVariation $variation The variation to resolve image for
     * @param list<ProductImage> $parentImages Parent product's images array
     *
     * @return string|null The image URL, or null if none available
     */
    public function resolveUrl(ProductVariation $variation, array $parentImages): ?string
    {
        return $this->resolve($variation, $parentImages)?->url;
    }
}
