<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

/**
 * Tests for AddInventoryItemCommand.
 */
#[CoversClass(AddInventoryItemCommand::class)]
final class AddInventoryItemCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_with_required_fields(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('NEW-SKU-001'),
            title: 'Test Product',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: Money::exclusive(15.00),
            taxRate: TaxRate::standard(),
        );

        self::assertSame('NEW-SKU-001', $command->sku->value);
        self::assertSame('Test Product', $command->title);
        self::assertSame(29.99, $command->retailPrice->toGross());
        self::assertSame(15.00, $command->purchasePrice?->toNet());
        self::assertSame(20.0, $command->taxRate->percentage);
    }

    #[Test]
    public function it_creates_with_all_optional_fields(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('FULL-SKU'),
            title: 'Full Product',
            retailPrice: Money::inclusive(49.99),
            purchasePrice: Money::exclusive(25.00),
            taxRate: TaxRate::zero(),
            barcode: Gtin::fromTrusted('9780201633610'),
            mpn: 'MPN-12345',
        );

        self::assertSame('9780201633610', $command->barcode?->value);
        self::assertSame('MPN-12345', $command->mpn);
    }

    #[Test]
    public function it_allows_null_purchase_price(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('NO-COST-SKU'),
            title: 'Unknown Cost Product',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
        );

        self::assertNull($command->purchasePrice);
    }

    #[Test]
    public function it_allows_null_barcode(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('NO-BARCODE'),
            title: 'Product Without Barcode',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
            barcode: null,
        );

        self::assertNull($command->barcode);
    }

    #[Test]
    public function it_allows_null_mpn(): void
    {
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('NO-MPN'),
            title: 'Product Without MPN',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
            mpn: null,
        );

        self::assertNull($command->mpn);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_rejects_empty_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title cannot be empty');

        new AddInventoryItemCommand(
            sku: Sku::fromTrusted('EMPTY-TITLE'),
            title: '',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
        );
    }

    #[Test]
    public function it_rejects_whitespace_only_title(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title cannot be empty');

        new AddInventoryItemCommand(
            sku: Sku::fromTrusted('WHITESPACE-TITLE'),
            title: '   ',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
        );
    }

    #[Test]
    public function it_accepts_title_with_leading_trailing_whitespace(): void
    {
        // Title with whitespace is valid (whitespace trimmed in validation check only)
        $command = new AddInventoryItemCommand(
            sku: Sku::fromTrusted('PADDED-TITLE'),
            title: '  Valid Title  ',
            retailPrice: Money::inclusive(29.99),
            purchasePrice: null,
            taxRate: TaxRate::standard(),
        );

        // Note: title is NOT trimmed, only validated after trim
        self::assertSame('  Valid Title  ', $command->title);
    }
}
