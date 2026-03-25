<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Validators;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\Validators\PriceCommandsVatRoundTripValidator;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PriceCommandsVatRoundTripValidator::class)]
final class PriceCommandsVatRoundTripValidatorTest extends TestCase
{
    #[Test]
    public function it_passes_when_all_prices_are_vat_safe(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: Money::inclusive(24.00),
                salePrice: Money::inclusive(12.00),
            ),
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('DEF-456'),
                price: Money::inclusive(10.00),
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->passed());
        self::assertFalse($result->failed());
    }

    #[Test]
    public function it_fails_when_price_is_vat_unsafe(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: Money::inclusive(0.03),
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->failed());
        self::assertArrayHasKey('ABC-123.price', $result->context());
        self::assertSame('ABC-123', $result->context()['ABC-123.price']['sku']);
        self::assertSame('price', $result->context()['ABC-123.price']['field']);
    }

    #[Test]
    public function it_fails_when_sale_price_is_vat_unsafe(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: Money::inclusive(24.00),
                salePrice: Money::inclusive(0.03),
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->failed());
        self::assertArrayHasKey('ABC-123.salePrice', $result->context());
        self::assertSame('salePrice', $result->context()['ABC-123.salePrice']['field']);
    }

    #[Test]
    public function it_skips_null_prices(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: null,
                salePrice: null,
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->passed());
        self::assertSame([], $result->context());
    }

    #[Test]
    public function it_skips_zero_sale_price(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: Money::inclusive(24.00),
                salePrice: Money::inclusive(0),
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->passed());
    }

    #[Test]
    public function it_reports_multiple_failures_across_commands(): void
    {
        $commands = [
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('ABC-123'),
                price: Money::inclusive(0.03),
            ),
            new UpdatePriceCommand(
                sku: Sku::fromTrusted('DEF-456'),
                price: Money::inclusive(24.00),
                salePrice: Money::inclusive(0.09),
            ),
        ];

        $result = (new PriceCommandsVatRoundTripValidator($commands, TaxRate::standard()))->validate();

        self::assertTrue($result->failed());

        $context = $result->context();
        self::assertCount(2, $context);
        self::assertArrayHasKey('ABC-123.price', $context);
        self::assertArrayHasKey('DEF-456.salePrice', $context);
    }
}
