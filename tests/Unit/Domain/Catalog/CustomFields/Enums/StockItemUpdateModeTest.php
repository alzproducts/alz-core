<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\Enums;

use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockItemUpdateMode::class)]
final class StockItemUpdateModeTest extends TestCase
{
    #[Test]
    public function exposes_stable_string_values(): void
    {
        self::assertSame('single', StockItemUpdateMode::Single->value);
        self::assertSame('all_variants', StockItemUpdateMode::AllVariants->value);
    }

    #[Test]
    public function from_parses_known_values(): void
    {
        self::assertSame(StockItemUpdateMode::Single, StockItemUpdateMode::from('single'));
        self::assertSame(StockItemUpdateMode::AllVariants, StockItemUpdateMode::from('all_variants'));
    }

    #[Test]
    public function try_from_returns_null_for_unknown_value(): void
    {
        self::assertNull(StockItemUpdateMode::tryFrom('variant'));
        self::assertNull(StockItemUpdateMode::tryFrom(''));
    }

    #[Test]
    public function defines_exactly_two_cases(): void
    {
        self::assertCount(2, StockItemUpdateMode::cases());
    }
}
