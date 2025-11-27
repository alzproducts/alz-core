<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\ValueObjects\CategoryImage as DomainCategoryImage;
use App\Infrastructure\Shopwired\Responses\CategoryImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CategoryImage DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for parsing ShopWired category image responses.
 * Verifies API response parsing and domain object conversion.
 */
#[CoversClass(CategoryImage::class)]
final class CategoryImageTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | from() - API Response Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_url_from_api_response(): void
    {
        $payload = ['url' => 'https://cdn.shopwired.com/images/category-123.jpg'];

        $dto = CategoryImage::from($payload);

        $this->assertSame('https://cdn.shopwired.com/images/category-123.jpg', $dto->url);
    }

    #[Test]
    public function from_accepts_any_valid_url_string(): void
    {
        $payload = ['url' => 'https://example.com/path/to/image.png?v=123&size=large'];

        $dto = CategoryImage::from($payload);

        $this->assertSame('https://example.com/path/to/image.png?v=123&size=large', $dto->url);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() - Domain Conversion
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_returns_domain_category_image(): void
    {
        $dto = new CategoryImage(url: 'https://cdn.example.com/image.jpg');

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainCategoryImage::class, $domain);
    }

    #[Test]
    public function to_domain_preserves_url_value(): void
    {
        $expectedUrl = 'https://cdn.shopwired.com/categories/electronics.webp';
        $dto = new CategoryImage(url: $expectedUrl);

        $domain = $dto->toDomain();

        $this->assertSame($expectedUrl, $domain->url);
    }

    #[Test]
    public function to_domain_creates_new_instance_each_call(): void
    {
        $dto = new CategoryImage(url: 'https://cdn.example.com/image.jpg');

        $domain1 = $dto->toDomain();
        $domain2 = $dto->toDomain();

        $this->assertNotSame($domain1, $domain2);
        $this->assertEquals($domain1, $domain2);
    }
}
