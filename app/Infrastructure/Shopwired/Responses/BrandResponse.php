<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Brand\ValueObjects\Brand as DomainBrand;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Brand
 *
 * Infrastructure DTO for parsing brand API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * @see https://shopwired.readme.io/docs/brands
 */
#[MapInputName(SnakeCaseMapper::class)]
final class BrandResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param array<string, mixed> $customFields Custom field key-value pairs (requires custom_fields embed)
     */
    public function __construct(
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

        // Embed: customFields requires embed=custom_fields
        // API omits key entirely when entity has no custom fields defined
        public readonly array $customFields = [],
    ) {}

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
            image: $this->image?->url !== null ? $this->image->toDomain() : null,
            customFields: $this->customFields,
        );
    }
}
