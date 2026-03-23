<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * ShopWired Webhook Response: Product.
 *
 * Webhook payloads don't include embed data (vatRelief, variations, images,
 * categories, customFields, filters). Uses Spatie Optional for embed fields
 * so missing data is detected rather than silently defaulting.
 *
 * @see ProductResponse for the strict API client DTO (all embeds required)
 * @see https://shopwired.readme.io/reference/getproduct
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductWebhookResponse extends Data
{
    /**
     * @param list<ProductVariationResponse> $variations
     * @param list<ProductImageResponse> $images
     * @param list<array{id: int, title: string, ...}> $categories Raw category objects from embed
     * @param array<string, mixed> $customFields Raw custom fields from API (name => value)
     * @param array<int|string, list<string>> $filters Raw filter data from API (optionNo => values)
     */
    public function __construct(
        // Core fields — always present in webhooks
        public readonly int $id,
        public readonly ?string $sku,
        public readonly ?string $gtin,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $slug,
        public readonly string $url,
        public readonly float $price,
        public readonly ?float $costPrice,
        public readonly ?float $salePrice,
        public readonly ?float $comparePrice,
        public readonly int $stock,
        public readonly ?int $sortOrder,
        #[MapInputName('active')]
        public readonly bool $isActive,
        public readonly bool $vatExclusive,
        public readonly ?float $weight,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly string $createdAt,
        public readonly string $updatedAt,

        // Embed fields — Optional (may be absent from webhooks)
        public readonly bool|Optional $vatRelief = new Optional(),
        #[DataCollectionOf(ProductVariationResponse::class)]
        public readonly array|Optional $variations = new Optional(),
        #[DataCollectionOf(ProductImageResponse::class)]
        public readonly array|Optional $images = new Optional(),
        public readonly array|Optional $categories = new Optional(),
        public readonly array|Optional $customFields = new Optional(),
        public readonly array|Optional $filters = new Optional(),
    ) {}

    /**
     * Returns the list of embed names that were actually present in the payload.
     *
     * @return list<string> Embed names (matching ShopWired API embed names)
     */
    public function presentEmbeds(): array
    {
        $embeds = [];

        if (! $this->vatRelief instanceof Optional) {
            $embeds[] = 'vat_relief';
        }

        if (! $this->variations instanceof Optional) {
            $embeds[] = 'variations';
        }

        if (! $this->images instanceof Optional) {
            $embeds[] = 'images';
        }

        if (! $this->categories instanceof Optional) {
            $embeds[] = 'categories';
        }

        if (! $this->customFields instanceof Optional) {
            $embeds[] = 'custom_fields';
        }

        if (! $this->filters instanceof Optional) {
            $embeds[] = 'filters';
        }

        return $embeds;
    }

    /**
     * Extract category IDs from embedded category objects.
     *
     * Returns empty array if categories embed was not present.
     *
     * @return list<int>
     */
    public function getCategoryIds(): array
    {
        if ($this->categories instanceof Optional) {
            return [];
        }

        return \array_map(
            static fn(array $category): int => $category['id'],
            $this->categories,
        );
    }
}
