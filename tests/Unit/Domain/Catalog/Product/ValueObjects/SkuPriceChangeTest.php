<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Catalog\Product\ValueObjects\SkuPriceChange;
use App\Domain\Shared\Money\ValueObjects\Money;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SkuPriceChange::class)]
final class SkuPriceChangeTest extends TestCase
{
    // ========================================================================
    // addedToSale()
    // ========================================================================

    #[Test]
    public function added_to_sale_when_was_not_on_sale_now_is(): void
    {
        $change = self::makeChange(
            previousSale: null,
            newSale: 15.00,
        );

        self::assertTrue($change->addedToSale());
        self::assertFalse($change->removedFromSale());
        self::assertFalse($change->saleChanged());
    }

    #[Test]
    public function not_added_to_sale_when_was_already_on_sale(): void
    {
        $change = self::makeChange(
            previousSale: 12.00,
            newSale: 15.00,
        );

        self::assertFalse($change->addedToSale());
    }

    #[Test]
    public function not_added_to_sale_when_both_have_no_sale(): void
    {
        $change = self::makeChange(
            previousSale: null,
            newSale: null,
        );

        self::assertFalse($change->addedToSale());
    }

    // ========================================================================
    // removedFromSale()
    // ========================================================================

    #[Test]
    public function removed_from_sale_when_was_on_sale_now_is_not(): void
    {
        $change = self::makeChange(
            previousSale: 15.00,
            newSale: null,
        );

        self::assertTrue($change->removedFromSale());
        self::assertFalse($change->addedToSale());
        self::assertFalse($change->saleChanged());
    }

    #[Test]
    public function not_removed_from_sale_when_was_not_on_sale(): void
    {
        $change = self::makeChange(
            previousSale: null,
            newSale: null,
        );

        self::assertFalse($change->removedFromSale());
    }

    // ========================================================================
    // saleChanged()
    // ========================================================================

    #[Test]
    public function sale_changed_when_both_have_active_sales_with_different_amounts(): void
    {
        $change = self::makeChange(
            previousSale: 15.00,
            newSale: 12.00,
        );

        self::assertTrue($change->saleChanged());
        self::assertFalse($change->addedToSale());
        self::assertFalse($change->removedFromSale());
    }

    #[Test]
    public function sale_not_changed_when_both_have_same_sale_amount(): void
    {
        $change = self::makeChange(
            previousSale: 15.00,
            newSale: 15.00,
        );

        self::assertFalse($change->saleChanged());
    }

    #[Test]
    public function sale_not_changed_when_previous_had_no_sale(): void
    {
        $change = self::makeChange(
            previousSale: null,
            newSale: 15.00,
        );

        self::assertFalse($change->saleChanged());
    }

    #[Test]
    public function sale_not_changed_when_new_has_no_sale(): void
    {
        $change = self::makeChange(
            previousSale: 15.00,
            newSale: null,
        );

        self::assertFalse($change->saleChanged());
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private static function makeChange(?float $previousSale, ?float $newSale): SkuPriceChange
    {
        return new SkuPriceChange(
            sku: Sku::fromTrusted('TEST-001'),
            previousPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: $previousSale !== null ? Money::inclusive($previousSale) : null,
            ),
            newPrices: new ProductRetailPricing(
                basePrice: Money::inclusive(20.00),
                salePrice: $newSale !== null ? Money::inclusive($newSale) : null,
            ),
        );
    }
}
