<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\ResolvedPriceUpdate;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResolvedPriceUpdate::class)]
final class ResolvedPriceUpdateTest extends TestCase
{
    #[Test]
    public function both_fields_null_carries_forward_all_current_pricing(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(20.0, $resolved->effectivePricing->basePrice->toGross());
        self::assertSame(15.0, $resolved->effectivePricing->salePrice->toGross());
    }

    #[Test]
    public function price_set_sale_null_uses_new_base_carries_forward_sale(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(25.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(25.0, $resolved->effectivePricing->basePrice->toGross());
        self::assertSame(15.0, $resolved->effectivePricing->salePrice->toGross());
    }

    #[Test]
    public function price_null_sale_zero_carries_forward_base_clears_sale(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            salePrice: Money::inclusive(0.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(20.0, $resolved->effectivePricing->basePrice->toGross());
        self::assertNull($resolved->effectivePricing->salePrice);
        self::assertFalse($resolved->effectivePricing->saleActive());
    }

    #[Test]
    public function both_set_full_override(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: Money::inclusive(15.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(30.00),
            salePrice: Money::inclusive(22.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(30.0, $resolved->effectivePricing->basePrice->toGross());
        self::assertSame(22.0, $resolved->effectivePricing->salePrice->toGross());
    }

    #[Test]
    public function carries_forward_null_sale_from_current(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            salePrice: null,
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(25.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(25.0, $resolved->effectivePricing->basePrice->toGross());
        self::assertNull($resolved->effectivePricing->salePrice);
    }

    #[Test]
    public function preserves_original_command_and_current_pricing(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(25.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame($command, $resolved->command);
        self::assertSame($current, $resolved->currentPricing);
        self::assertSame('TEST-001', $resolved->sku->value);
    }

    // ========================================================================
    // rrp carry-forward
    // ========================================================================

    #[Test]
    public function rrp_null_carries_forward_from_current(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            rrp: Money::inclusive(29.99),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(25.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(29.99, $resolved->effectivePricing->rrp->toGross());
    }

    #[Test]
    public function rrp_override_replaces_current(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            rrp: Money::inclusive(29.99),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            rrp: Money::inclusive(35.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertSame(35.0, $resolved->effectivePricing->rrp->toGross());
    }

    #[Test]
    public function rrp_zero_clears_current_rrp(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
            rrp: Money::inclusive(29.99),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            rrp: Money::inclusive(0.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertNull($resolved->effectivePricing->rrp);
    }

    #[Test]
    public function rrp_null_in_both_current_and_command_stays_null(): void
    {
        $current = new ProductRetailPricing(
            basePrice: Money::inclusive(20.00),
        );
        $command = new UpdatePriceCommand(
            sku: Sku::fromTrusted('TEST-001'),
            price: Money::inclusive(25.00),
        );

        $resolved = ResolvedPriceUpdate::fromCommand($command, $current);

        self::assertNull($resolved->effectivePricing->rrp);
    }
}
