<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Validators\PriceChangedResult;
use App\Domain\Catalog\Product\Validators\PriceChangedValidator;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceChangedValidator::class)]
#[CoversClass(PriceChangedResult::class)]
final class PriceChangedValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_base_price_differs(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(10.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
    }

    #[Test]
    public function it_passes_when_sale_price_differs(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(10.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_passes_when_sale_added(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: null,
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_passes_when_sale_removed(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: null,
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_fails_when_prices_are_identical(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertStringContainsString('Prices unchanged', $result->reason());
        self::assertSame(20.0, $result->context()['base_gross']);
        self::assertSame(15.0, $result->context()['sale_gross']);
    }

    #[Test]
    public function it_fails_when_both_have_no_sale_and_same_base(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
    }

    #[Test]
    public function it_detects_tax_type_change_as_different(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::exclusive(20.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function reason_is_empty_when_passed(): void
    {
        $result = (new PriceChangedValidator(
            proposed: new ProductRetailPricing(
                basePrice: Money::inclusive(30.00),
            ),
            current: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }
}
