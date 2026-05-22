<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\ValueObjects;

use App\Domain\Catalog\Product\ValueObjects\StockStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StockStatus::class)]
final class StockStatusTest extends TestCase
{
    #[Test]
    public function all_properties_null_when_constructed_with_nulls(): void
    {
        $status = new StockStatus(null, null, null);

        self::assertNull($status->discontinued);
        self::assertNull($status->preorderDate);
        self::assertNull($status->otherStockStatus);
    }

    #[Test]
    public function all_properties_populated_when_provided(): void
    {
        $preorderDate = new DateTimeImmutable('2026-06-15T00:00:00+00:00');
        $status = new StockStatus('yes', $preorderDate, 'awaiting_restock');

        self::assertSame('yes', $status->discontinued);
        self::assertSame($preorderDate, $status->preorderDate);
        self::assertSame('awaiting_restock', $status->otherStockStatus);
    }

    #[Test]
    public function only_discontinued_set(): void
    {
        $status = new StockStatus('yes', null, null);

        self::assertSame('yes', $status->discontinued);
        self::assertNull($status->preorderDate);
        self::assertNull($status->otherStockStatus);
    }

    #[Test]
    public function only_preorder_date_set(): void
    {
        $preorderDate = new DateTimeImmutable('2026-08-01T00:00:00+00:00');
        $status = new StockStatus(null, $preorderDate, null);

        self::assertNull($status->discontinued);
        self::assertSame($preorderDate, $status->preorderDate);
        self::assertNull($status->otherStockStatus);
    }

    #[Test]
    public function only_other_stock_status_set(): void
    {
        $status = new StockStatus(null, null, 'awaiting_restock');

        self::assertNull($status->discontinued);
        self::assertNull($status->preorderDate);
        self::assertSame('awaiting_restock', $status->otherStockStatus);
    }
}
