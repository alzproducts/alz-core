<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductVariationOption;
use App\Domain\Exceptions\InvalidGtinException;
use App\Infrastructure\Contracts\DomainConvertibleChildInterface;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Product Variation.
 *
 * Represents a purchasable variant of a product (e.g., "Large, Red").
 * Nested within ProductResponse.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductVariationResponse extends Data implements DomainConvertibleChildInterface
{
    /**
     * @param ?float $price null = inherit parent price, 0.00 = temporarily removed from sale
     * @param list<ProductVariationOptionResponse> $values API returns "values" not "options"
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $sku,
        public readonly ?float $price,
        public readonly ?float $costPrice,
        public readonly ?float $salePrice,
        public readonly int $stock,
        public readonly ?float $weight,
        public readonly ?string $gtin,
        public readonly ?string $mpn,
        #[MapInputName('image')]
        public readonly ?int $imageIndex,
        #[DataCollectionOf(ProductVariationOptionResponse::class)]
        public readonly array $values = [],
    ) {}

    /**
     * Convert to domain value object.
     *
     * @param int|string $parentId Parent product's ShopWired ID (required for sync key)
     */
    public function toDomain(int|string $parentId): ProductVariation
    {
        return new ProductVariation(
            id: $this->id,
            productExternalId: (int) $parentId,
            sku: $this->sku,
            price: $this->price,
            costPrice: $this->costPrice,
            salePrice: $this->salePrice,
            stock: $this->stock,
            weight: $this->weight,
            gtin: $this->parseGtin(),
            mpn: $this->mpn,
            imageIndex: $this->imageIndex,
            options: \array_map(
                static fn(ProductVariationOptionResponse $opt): ProductVariationOption => $opt->toDomain(),
                $this->values,
            ),
        );
    }

    /**
     * Parse GTIN string to value object, logging warnings for invalid values.
     *
     * Invalid GTINs are logged but not fatal - products can sync without valid barcodes.
     */
    private function parseGtin(): ?Gtin
    {
        // 'Does not apply' is a common placeholder in ShopWired for products without GTINs
        if ($this->gtin === null || $this->gtin === '' || $this->gtin === 'Does not apply') {
            return null;
        }

        try {
            return Gtin::fromString($this->gtin);
        } catch (InvalidGtinException $e) {
            Log::warning('Invalid GTIN in product variation', [
                'variation_id' => $this->id,
                'gtin' => $this->gtin,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
