<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Enums;

use App\Domain\Catalog\Product\Enums\SaleRemovalReason;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaleRemovalReason::class)]
final class SaleRemovalReasonTest extends TestCase
{
    /**
     * @return iterable<string, array{SaleRemovalReason, string}>
     */
    public static function labelProvider(): iterable
    {
        yield 'manual' => [SaleRemovalReason::Manual, 'Manual removal'];
        yield 'product_inactive' => [SaleRemovalReason::ProductInactive, 'Product inactive'];
        yield 'end_date_reached' => [SaleRemovalReason::EndDateReached, 'Sale end date reached'];
        yield 'out_of_stock_discontinued' => [SaleRemovalReason::OutOfStockDiscontinued, 'Out of stock (discontinued)'];
        yield 'sale_units_sold' => [SaleRemovalReason::SaleUnitsSold, 'Sale units sold'];
    }

    #[Test]
    #[DataProvider('labelProvider')]
    public function label_returns_human_readable_string(SaleRemovalReason $reason, string $expectedLabel): void
    {
        self::assertSame($expectedLabel, $reason->label());
    }

    #[Test]
    public function all_cases_have_labels(): void
    {
        foreach (SaleRemovalReason::cases() as $case) {
            self::assertNotEmpty($case->label(), "Case {$case->name} has empty label");
        }
    }

    #[Test]
    public function backed_values_are_snake_case(): void
    {
        self::assertSame('manual', SaleRemovalReason::Manual->value);
        self::assertSame('product_inactive', SaleRemovalReason::ProductInactive->value);
        self::assertSame('end_date_reached', SaleRemovalReason::EndDateReached->value);
        self::assertSame('out_of_stock_discontinued', SaleRemovalReason::OutOfStockDiscontinued->value);
        self::assertSame('sale_units_sold', SaleRemovalReason::SaleUnitsSold->value);
    }
}
