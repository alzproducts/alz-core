<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\Brand as DomainBrand;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * ShopWired Webhook Response: Brand.
 *
 * Webhook payloads don't include embed data (customFields).
 * Uses Spatie Optional for embed fields so missing data is detected
 * rather than silently defaulting to empty arrays.
 *
 * @see BrandResponse for the strict API client DTO (all embeds required)
 */
#[MapInputName(SnakeCaseMapper::class)]
final class BrandWebhookResponse extends Data
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        // Core fields — always present in webhooks
        public readonly int $id,
        public readonly string $createdAt,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $slug,
        public readonly string $url,
        public readonly bool $active,
        public readonly bool $featured,
        public readonly int $sortOrder,
        public readonly ?string $metaTitle,
        public readonly ?string $metaKeywords,
        public readonly ?string $metaDescription,

        // Standard nullable field (not an embed)
        public readonly ?BrandImageResponse $image = null,

        // Embed field — Optional (may be absent from webhooks)
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

        if (! $this->customFields instanceof Optional) {
            $embeds[] = 'custom_fields';
        }

        return $embeds;
    }

    /**
     * Convert to Domain Value Object.
     *
     * @throws InvalidApiResponseException When date format is invalid
     */
    public function toDomain(): DomainBrand
    {
        try {
            $createdAt = new DateTimeImmutable($this->createdAt);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Invalid date format in brand {$this->id}",
                previous: $e,
            );
        }

        return new DomainBrand(
            id: $this->id,
            createdAt: $createdAt,
            title: $this->title,
            description: $this->description,
            slug: $this->slug,
            url: $this->url,
            active: $this->active,
            featured: $this->featured,
            sortOrder: $this->sortOrder,
            metaTitle: $this->metaTitle,
            metaKeywords: $this->metaKeywords,
            metaDescription: $this->metaDescription,
            image: $this->image?->toDomain(),
            customFields: $this->customFields instanceof Optional ? [] : $this->customFields,
        );
    }
}
