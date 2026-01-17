<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Product.
 *
 * Main product DTO. Does NOT implement DomainConvertibleInterface because
 * custom field transformation requires the CustomFieldDefinitionRegistry.
 * Use ProductDomainFactory::fromResponse() to convert to Domain.
 *
 * @see https://shopwired.readme.io/reference/getproduct
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductResponse extends Data
{
    /**
     * @param list<ProductVariationResponse> $variations
     * @param list<ProductImageResponse> $images
     * @param list<int> $categoryIds
     * @param array<string, mixed> $customFields Raw custom fields from API (name => value)
     */
    public function __construct(
        // Identifiers
        public readonly int $id,
        public readonly ?string $sku,
        public readonly ?string $gtin,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $slug,
        public readonly string $url,

        // Pricing
        public readonly float $price,
        public readonly ?float $costPrice,
        public readonly ?float $salePrice,
        public readonly ?float $comparePrice,

        // Inventory
        public readonly int $stock,

        // Flags
        public readonly bool $isActive,
        public readonly bool $vatExclusive,
        public readonly bool $vatRelief,

        // Shipping
        public readonly ?float $weight,

        // SEO
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,

        // Timestamps
        public readonly CarbonImmutable $createdAt,
        public readonly CarbonImmutable $updatedAt,

        // Relations (must come after required fields)
        #[DataCollectionOf(ProductVariationResponse::class)]
        public readonly array $variations = [],
        #[DataCollectionOf(ProductImageResponse::class)]
        public readonly array $images = [],
        public readonly array $categoryIds = [],
        public readonly array $customFields = [],
    ) {}
}
