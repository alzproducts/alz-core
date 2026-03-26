<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\ValueObjects;

use App\Domain\Catalog\Brand\ValueObjects\BrandImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrandImage::class)]
final class BrandImageTest extends TestCase
{
    #[Test]
    public function it_constructs_and_holds_url_correctly(): void
    {
        // Arrange
        $url = 'https://example.com/images/brand.jpg';

        // Act
        $brandImage = new BrandImage($url);

        // Assert
        self::assertSame($url, $brandImage->url);
    }

    #[Test]
    public function it_accepts_an_empty_string_for_url(): void
    {
        // Hypothesis: The value object has no validation beyond type hints,
        // so it should accept an empty string. This test documents that behavior.

        // Arrange
        $url = '';

        // Act
        $brandImage = new BrandImage($url);

        // Assert
        self::assertSame($url, $brandImage->url);
    }
}
