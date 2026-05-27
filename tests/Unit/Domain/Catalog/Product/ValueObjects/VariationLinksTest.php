<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\VariationLinks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VariationLinks::class)]
final class VariationLinksTest extends TestCase
{
    #[Test]
    public function public_url_appends_var_query_param_when_sku_present(): void
    {
        $links = new VariationLinks(
            parentPublicUrl: 'https://example.com/products/widget',
            editWebsiteUrl: 'https://admin.example.com/products/123',
            sku: Sku::fromTrusted('WIDGET-RED-LG'),
        );

        self::assertSame('https://example.com/products/widget?var=WIDGET-RED-LG', $links->publicUrl);
    }

    #[Test]
    public function public_url_uses_parent_url_unchanged_when_sku_null(): void
    {
        $links = new VariationLinks(
            parentPublicUrl: 'https://example.com/products/widget',
            editWebsiteUrl: 'https://admin.example.com/products/123',
            sku: null,
        );

        self::assertSame('https://example.com/products/widget', $links->publicUrl);
    }

    #[Test]
    public function public_url_urlencodes_special_chars_in_sku(): void
    {
        $links = new VariationLinks(
            parentPublicUrl: 'https://example.com/products/widget',
            editWebsiteUrl: 'https://admin.example.com/products/123',
            sku: Sku::fromTrusted('WIDGET RED/LG'),
        );

        self::assertSame('https://example.com/products/widget?var=WIDGET+RED%2FLG', $links->publicUrl);
    }

    #[Test]
    public function edit_website_url_stored_as_is(): void
    {
        $links = new VariationLinks(
            parentPublicUrl: 'https://example.com/products/widget',
            editWebsiteUrl: 'https://admin.example.com/products/123/edit',
            sku: null,
        );

        self::assertSame('https://admin.example.com/products/123/edit', $links->editWebsiteUrl);
    }
}
