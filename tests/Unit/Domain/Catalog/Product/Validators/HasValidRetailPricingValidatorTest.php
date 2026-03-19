<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Validators\HasValidRetailPricingResult;
use App\Domain\Catalog\Product\Validators\HasValidRetailPricingValidator;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HasValidRetailPricingValidator::class)]
#[CoversClass(HasValidRetailPricingResult::class)]
final class HasValidRetailPricingValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_no_sale_price(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
    }

    #[Test]
    public function it_passes_when_sale_price_is_zero(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(0.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_passes_when_sale_less_than_base(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(15.00),
            ),
        ))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_fails_when_base_price_is_zero(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(0.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertStringContainsString('basePrice', $result->reason());
        self::assertStringContainsString('must be greater than zero', $result->reason());
        self::assertSame(0.0, $result->context()['base_gross']);
    }

    #[Test]
    public function it_fails_when_base_price_is_zero_even_with_zero_sale(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(0.00),
                salePrice: Money::inclusive(0.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertStringContainsString('must be greater than zero', $result->reason());
    }

    #[Test]
    public function it_fails_when_sale_equals_base(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertStringContainsString('salePrice', $result->reason());
        self::assertStringContainsString('must be less than', $result->reason());
        self::assertSame(20.0, $result->context()['base_gross']);
        self::assertSame(20.0, $result->context()['sale_gross']);
    }

    #[Test]
    public function it_fails_when_sale_exceeds_base(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(10.00),
                salePrice: Money::inclusive(25.00),
            ),
        ))->validate();

        self::assertTrue($result->failed());
        self::assertSame(10.0, $result->context()['base_gross']);
        self::assertSame(25.0, $result->context()['sale_gross']);
    }

    #[Test]
    public function or_fail_throws_on_failure(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(10.00),
                salePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        try {
            $result->orFail();
            self::fail('Expected ValidationFailedException was not thrown');
        } catch (ValidationFailedException $e) {
            self::assertStringContainsString('salePrice', $e->reason());
            self::assertSame(10.0, $e->context()['base_gross']);
            self::assertSame(20.0, $e->context()['sale_gross']);
        }
    }

    #[Test]
    public function or_fail_is_noop_on_success(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: Money::inclusive(10.00),
            ),
        ))->validate();

        $result->orFail();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function reason_is_empty_when_passed(): void
    {
        $result = (new HasValidRetailPricingValidator(
            pricing: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
            ),
        ))->validate();

        self::assertSame('', $result->reason());
        self::assertSame([], $result->context());
    }
}
