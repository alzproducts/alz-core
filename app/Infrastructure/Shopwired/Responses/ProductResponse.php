<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

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
     * @param list<array{id: int, title: string, ...}> $categories Raw category objects from embed
     * @param array<string, mixed> $customFields Raw custom fields from API (name => value)
     * @param array<int|string, list<string>> $filters Raw filter data from API (optionNo => values)
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
        #[MapInputName('active')]
        public readonly bool $isActive,
        public readonly bool $vatExclusive,
        public readonly bool $vatRelief,

        // Shipping
        public readonly ?float $weight,

        // SEO
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,

        // Timestamps (stored as strings, parsed manually to handle RFC 2822 format)
        public readonly string $createdAt,
        public readonly string $updatedAt,

        // Relations (must come after required fields)
        #[DataCollectionOf(ProductVariationResponse::class)]
        public readonly array $variations = [],
        #[DataCollectionOf(ProductImageResponse::class)]
        public readonly array $images = [],
        public readonly array $categories = [],
        public readonly array $customFields = [],
        public readonly array $filters = [],
    ) {}

    /**
     * Extract category IDs from embedded category objects.
     *
     * @return list<int>
     */
    public function getCategoryIds(): array
    {
        return \array_map(
            static fn(array $category): int => $category['id'],
            $this->categories,
        );
    }
}
