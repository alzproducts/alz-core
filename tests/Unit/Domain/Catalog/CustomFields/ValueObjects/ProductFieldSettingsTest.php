<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\LinnworksStockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProductFieldSettings::class)]
final class ProductFieldSettingsTest extends TestCase
{
    #[Test]
    public function constructor_stores_single_mode(): void
    {
        $settings = new ProductFieldSettings(LinnworksStockItemUpdateMode::Single);

        self::assertSame(LinnworksStockItemUpdateMode::Single, $settings->updateLinnworksStockItem);
    }

    #[Test]
    public function constructor_stores_all_variants_mode(): void
    {
        $settings = new ProductFieldSettings(LinnworksStockItemUpdateMode::AllVariants);

        self::assertSame(LinnworksStockItemUpdateMode::AllVariants, $settings->updateLinnworksStockItem);
    }

    #[Test]
    public function constructor_accepts_null_update_mode(): void
    {
        $settings = new ProductFieldSettings(null);

        self::assertNull($settings->updateLinnworksStockItem);
    }
}
