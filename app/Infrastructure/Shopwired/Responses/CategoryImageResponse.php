<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\CategoryImage as DomainCategoryImage;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;
use Webmozart\Assert\Assert;

/**
 * ShopWired API Response: Category Image
 *
 * Nested object within Category response containing the image URL.
 * Named CategoryImage to avoid collision with Product images later.
 *
 * @see Category::$image
 */
final class CategoryImageResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly ?string $url,
    ) {}

    /**
     * Convert to Domain Value Object.
     */
    public function toDomain(): DomainCategoryImage
    {
        Assert::notNull($this->url, 'toDomain() must not be called when url is null');

        return new DomainCategoryImage(
            url: $this->url,
        );
    }
}
