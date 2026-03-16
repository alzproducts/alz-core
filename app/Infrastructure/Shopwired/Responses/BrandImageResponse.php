<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\BrandImage as DomainBrandImage;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;

/**
 * ShopWired API Response: Brand Image
 *
 * Nested object within Brand response containing the image URL.
 *
 * @see BrandResponse::$image
 */
final class BrandImageResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly string $url,
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): DomainBrandImage
    {
        return new DomainBrandImage(
            url: $this->url,
        );
    }
}
