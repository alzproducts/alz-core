<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SyncOrderLineDTO;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\ValueObjects\TaxRate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Application\QuickBooks\OrderSync\SyncOrderFixture;

#[CoversClass(SyncOrderDTO::class)]
#[CoversClass(SyncOrderLineDTO::class)]
final class SyncOrderDTOTest extends TestCase
{
    #[Test]
    public function it_rejects_an_empty_order_reference(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must not be empty');

        SyncOrderFixture::order(reference: '');
    }

    #[Test]
    public function it_rejects_a_line_with_no_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SyncOrderFixture::line(quantity: 0);
    }

    #[Test]
    public function it_rejects_a_line_with_a_blank_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order line SKU must not be blank');

        new SyncOrderLineDTO(
            Sku::fromTrusted('  '),
            'Line',
            1,
            DecimalMoney::fromString('1'),
            DecimalMoney::fromString('0'),
            TaxRate::fromPercentage(0.0),
        );
    }

    #[Test]
    public function it_accepts_a_single_unit_line(): void
    {
        self::assertSame(1, SyncOrderFixture::line(quantity: 1)->quantity);
    }
}
