<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Validators\SkuBelongsToProductResult;
use App\Domain\Catalog\Product\Validators\SkuBelongsToProductValidator;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\ValidationFailedException;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuBelongsToProductValidator::class)]
#[CoversClass(SkuBelongsToProductResult::class)]
final class SkuBelongsToProductValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_all_skus_belong_to_product(): void
    {
        $product = self::createProduct('MASTER-001', [
            self::createVariation(1, 'VAR-001'),
            self::createVariation(2, 'VAR-002'),
        ]);

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [
                Sku::fromTrusted('MASTER-001'),
                Sku::fromTrusted('VAR-001'),
            ],
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
        self::assertSame([], $result->missingSkus());
        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }

    #[Test]
    public function it_fails_when_skus_are_missing(): void
    {
        $product = self::createProduct('MASTER-001', [
            self::createVariation(1, 'VAR-001'),
        ]);

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [
                Sku::fromTrusted('MASTER-001'),
                Sku::fromTrusted('MISSING-A'),
                Sku::fromTrusted('MISSING-B'),
            ],
        ))->validate();

        self::assertTrue($result->failed());
        self::assertFalse($result->passed());

        $missingValues = \array_map(
            static fn(Sku $s): string => $s->value,
            $result->missingSkus(),
        );
        self::assertSame(['MISSING-A', 'MISSING-B'], $missingValues);
    }

    #[Test]
    public function it_passes_when_required_skus_is_empty(): void
    {
        $product = self::createProduct('MASTER-001');

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [],
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function or_fail_throws_on_failure(): void
    {
        $product = self::createProduct('MASTER-001');

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [Sku::fromTrusted('NOPE')],
        ))->validate();

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('1 SKU(s) do not belong to the product', $e->reason());
            self::assertSame(['missing_skus' => ['NOPE']], $e->context());
        }
    }

    #[Test]
    public function or_fail_is_noop_on_success(): void
    {
        $product = self::createProduct('MASTER-001');

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [Sku::fromTrusted('MASTER-001')],
        ))->validate();

        // Should not throw
        $result->orFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function reason_includes_count_of_missing_skus(): void
    {
        $product = self::createProduct('MASTER-001');

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [
                Sku::fromTrusted('A'),
                Sku::fromTrusted('B'),
                Sku::fromTrusted('C'),
            ],
        ))->validate();

        self::assertSame(
            'SKU validation failed: 3 SKU(s) do not belong to the product',
            $result->reason(),
        );
    }

    #[Test]
    public function context_contains_missing_sku_values_as_strings(): void
    {
        $product = self::createProduct('MASTER-001');

        $result = (new SkuBelongsToProductValidator(
            product: $product,
            requiredSkus: [Sku::fromTrusted('MISSING-X')],
        ))->validate();

        self::assertSame(
            ['missing_skus' => ['MISSING-X']],
            $result->context(),
        );
    }

    // ========================================================================
    // Factory Helpers
    // ========================================================================

    /**
     * @param  list<ProductVariation>  $variations
     */
    private static function createProduct(string $masterSku, array $variations = []): Product
    {
        return new Product(
            id: 1,
            sku: $masterSku,
            gtin: null,
            title: 'Test Product',
            description: null,
            slug: 'test-product',
            url: 'https://example.com/test',
            price: 10.00,
            costPrice: null,
            salePrice: null,
            comparePrice: null,
            stock: 100,
            isActive: true,
            vatExclusive: false,
            vatRelief: false,
            weight: null,
            metaTitle: null,
            metaDescription: null,
            categoryIds: [],
            variations: $variations,
            images: [],
            rawCustomFields: [],
            customFields: [],
            rawFilters: [],
            filters: [],
            sortOrder: null,
            createdAt: new DateTimeImmutable('2024-01-01'),
            updatedAt: new DateTimeImmutable('2024-01-01'),
        );
    }

    private static function createVariation(int $id, string $sku): ProductVariation
    {
        return new ProductVariation(
            id: $id,
            productExternalId: 1,
            sku: $sku,
            price: 10.00,
            costPrice: null,
            salePrice: null,
            stock: 50,
            weight: null,
            gtin: null,
            mpn: null,
            imageIndex: null,
        );
    }
}
