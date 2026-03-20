<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(UpdatePriceCommand::class)]
final class UpdatePriceCommandTest extends TestCase
{
    #[Test]
    public function it_accepts_both_fields_null(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
        );

        self::assertNull($command->price);
        self::assertNull($command->salePrice);
    }

    #[Test]
    public function it_accepts_only_price_set(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
        );

        self::assertNotNull($command->price);
        self::assertNull($command->salePrice);
    }

    #[Test]
    public function it_accepts_only_sale_price_set(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            salePrice: Money::inclusive(15.00),
        );

        self::assertNull($command->price);
        self::assertNotNull($command->salePrice);
    }

    #[Test]
    public function it_accepts_sale_price_zero_to_clear_sale(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
            salePrice: Money::inclusive(0.00),
        );

        self::assertNotNull($command->salePrice);
        self::assertSame(0.0, $command->salePrice->toGross());
    }

    #[Test]
    public function it_accepts_valid_sale_less_than_price(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );

        self::assertSame(20.0, $command->price->toGross());
        self::assertSame(15.0, $command->salePrice->toGross());
    }

    #[Test]
    public function it_rejects_sale_price_equal_to_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('salePrice (£20.00) must be less than price (£20.00)');

        new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
            salePrice: Money::inclusive(20.00),
        );
    }

    #[Test]
    public function it_rejects_sale_price_greater_than_price(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('salePrice (£25.00) must be less than price (£20.00)');

        new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
            salePrice: Money::inclusive(25.00),
        );
    }

    // ========================================================================
    // hasAnyUpdate()
    // ========================================================================

    #[Test]
    public function has_any_update_returns_false_when_both_null(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
        );

        self::assertFalse($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_price_set(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(20.00),
        );

        self::assertTrue($command->hasAnyUpdate());
    }

    #[Test]
    public function has_any_update_returns_true_when_sale_price_set(): void
    {
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            salePrice: Money::inclusive(15.00),
        );

        self::assertTrue($command->hasAnyUpdate());
    }
}
