<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * ShopWired Webhook Response: Category.
 *
 * Webhook payloads don't include embed data (parents, customFields).
 * Uses Spatie Optional for embed fields so missing data is detected
 * rather than silently defaulting to empty arrays.
 *
 * @see CategoryResponse for the strict API client DTO (all embeds required)
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CategoryWebhookResponse extends Data
{
    /**
     * @param list<CategoryResponse> $parents
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        // Core fields — always present in webhooks
        public readonly int $id,
        public readonly string $createdAt,
        public readonly string $title,
        public readonly ?string $description,
        public readonly ?string $description2,
        public readonly string $slug,
        public readonly string $url,
        public readonly bool $active,
        public readonly bool $featured,
        public readonly bool $tradeOnly,
        public readonly int $sortOrder,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly ?string $metaKeywords,
        public readonly bool $metaNoIndex,

        // Standard nullable field (not an embed)
        public readonly ?CategoryImageResponse $image = null,

        // Embed fields — Optional (may be absent from webhooks)
        #[DataCollectionOf(CategoryResponse::class)]
        public readonly array|Optional $parents = new Optional(),
        public readonly array|Optional $customFields = new Optional(),
    ) {}

    /**
     * Returns the list of embed names that were actually present in the payload.
     *
     * @return list<string> Embed names (matching ShopWired API embed names)
     */
    public function presentEmbeds(): array
    {
        $embeds = [];

        if (! $this->parents instanceof Optional) {
            $embeds[] = 'parents';
        }

        if (! $this->customFields instanceof Optional) {
            $embeds[] = 'custom_fields';
        }

        return $embeds;
    }

    /**
     * Convert to Domain Value Object.
     *
     * Optional embed fields are coalesced to empty arrays for the domain layer,
     * which always expects concrete values. Use presentEmbeds() to determine
     * which fields should be persisted.
     *
     * @throws InvalidApiResponseException When date format is invalid
     */
    public function toDomain(): DomainCategory
    {
        try {
            $createdAt = new DateTimeImmutable($this->createdAt);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Invalid date format in category {$this->id}",
                previous: $e,
            );
        }

        $parentIds = $this->parents instanceof Optional
            ? []
            : \array_map(static fn(CategoryResponse $parent): int => $parent->id, $this->parents);

        return new DomainCategory(
            id: $this->id,
            createdAt: $createdAt,
            title: $this->title,
            description: $this->description,
            description2: $this->description2,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            tradeOnly: $this->tradeOnly,
            sortOrder: $this->sortOrder,
            metaTitle: $this->metaTitle,
            metaDescription: $this->metaDescription,
            metaKeywords: $this->metaKeywords,
            metaNoIndex: $this->metaNoIndex,
            image: $this->image?->toDomain(),
            parentIds: $parentIds,
            customFields: $this->customFields instanceof Optional ? [] : $this->customFields,
        );
    }
}
