<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\Enums;

use App\Domain\Catalog\CustomFields\Enums\LinnworksStockItemUpdateMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LinnworksStockItemUpdateMode::class)]
final class LinnworksStockItemUpdateModeTest extends TestCase
{
    #[Test]
    public function exposes_stable_string_values(): void
    {
        self::assertSame('single', LinnworksStockItemUpdateMode::Single->value);
        self::assertSame('all_variants', LinnworksStockItemUpdateMode::AllVariants->value);
    }

    #[Test]
    public function from_parses_known_values(): void
    {
        self::assertSame(LinnworksStockItemUpdateMode::Single, LinnworksStockItemUpdateMode::from('single'));
        self::assertSame(LinnworksStockItemUpdateMode::AllVariants, LinnworksStockItemUpdateMode::from('all_variants'));
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull(LinnworksStockItemUpdateMode::tryFrom('variant'));
        self::assertNull(LinnworksStockItemUpdateMode::tryFrom(''));
    }

    #[Test]
    public function defines_exactly_two_cases(): void
    {
        self::assertCount(2, LinnworksStockItemUpdateMode::cases());
    }
}
