<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Linnworks\Requests;

use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderCommand;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\TaxRate;
use App\Infrastructure\Linnworks\Requests\CreatePurchaseOrderInitialRequest;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(CreatePurchaseOrderInitialRequest::class)]
final class CreatePurchaseOrderInitialRequestTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | fromCommand — full field mapping
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_maps_all_fields_to_array_output(): void
    {
        $supplierId = new Guid('550e8400-e29b-41d4-a716-446655440000');
        $locationId = new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8');
        $command = new CreatePurchaseOrderCommand(
            fkSupplierId: $supplierId,
            fkLocationId: $locationId,
            reference: PurchaseOrderReference::fromString('PO1234567890'),
            items: [],
            currency: 'GBP',
            supplierReferenceNumber: 'SUP-REF-001',
            unitAmountTaxIncludedType: 1,
            dateOfPurchase: null,
            postagePaid: Money::exclusive(5.00),
            shippingTaxRate: TaxRate::standard(),
            quotedDeliveryDate: new DateTimeImmutable('2026-02-01T00:00:00'),
            conversionRate: 1.0,
        );
        $reference = PurchaseOrderReference::fromString('PO1234567890');
        $dateOfPurchase = new DateTimeImmutable('2026-01-15T10:30:00');

        $result = CreatePurchaseOrderInitialRequest::fromCommand($command, $reference, $dateOfPurchase)->toArray();

        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $result['fkSupplierId']);
        $this->assertSame('6ba7b810-9dad-11d1-80b4-00c04fd430c8', $result['fkLocationId']);
        $this->assertSame('PO1234567890', $result['ExternalInvoiceNumber']);
        $this->assertSame('GBP', $result['Currency']);
        $this->assertSame('SUP-REF-001', $result['SupplierReferenceNumber']);
        $this->assertSame(1, $result['UnitAmountTaxIncludedType']);
        $this->assertSame(5.00, $result['PostagePaid']);
        $this->assertSame(20.0, $result['ShippingTaxRate']);
        $this->assertSame(1.0, $result['ConversionRate']);
    }

    /*
    |--------------------------------------------------------------------------
    | fromCommand — date formatting
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_formats_date_of_purchase_as_iso_datetime(): void
    {
        $command = $this->makeCommand();
        $dateOfPurchase = new DateTimeImmutable('2026-01-15T10:30:00');

        $result = CreatePurchaseOrderInitialRequest::fromCommand(
            $command,
            PurchaseOrderReference::fromString('PO1234567890'),
            $dateOfPurchase,
        )->toArray();

        $this->assertSame('2026-01-15T10:30:00', $result['DateOfPurchase']);
    }

    #[Test]
    public function it_formats_quoted_delivery_date_as_iso_datetime_when_set(): void
    {
        $command = new CreatePurchaseOrderCommand(
            fkSupplierId: new Guid('550e8400-e29b-41d4-a716-446655440000'),
            fkLocationId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            reference: PurchaseOrderReference::fromString('PO1234567890'),
            items: [],
            currency: 'GBP',
            supplierReferenceNumber: 'SUP-REF-001',
            unitAmountTaxIncludedType: 1,
            dateOfPurchase: null,
            postagePaid: Money::exclusive(5.00),
            shippingTaxRate: TaxRate::standard(),
            quotedDeliveryDate: new DateTimeImmutable('2026-02-01T00:00:00'),
            conversionRate: 1.0,
        );

        $result = CreatePurchaseOrderInitialRequest::fromCommand(
            $command,
            PurchaseOrderReference::fromString('PO1234567890'),
            new DateTimeImmutable('2026-01-15T10:30:00'),
        )->toArray();

        $this->assertSame('2026-02-01T00:00:00', $result['QuotedDeliveryDate']);
    }

    #[Test]
    public function it_keeps_quoted_delivery_date_as_null_when_not_set(): void
    {
        $command = new CreatePurchaseOrderCommand(
            fkSupplierId: new Guid('550e8400-e29b-41d4-a716-446655440000'),
            fkLocationId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            reference: PurchaseOrderReference::fromString('PO1234567890'),
            items: [],
            currency: 'GBP',
            supplierReferenceNumber: 'SUP-REF-001',
            unitAmountTaxIncludedType: 1,
            dateOfPurchase: null,
            postagePaid: Money::exclusive(5.00),
            shippingTaxRate: TaxRate::standard(),
            quotedDeliveryDate: null,
            conversionRate: 1.0,
        );

        $result = CreatePurchaseOrderInitialRequest::fromCommand(
            $command,
            PurchaseOrderReference::fromString('PO1234567890'),
            new DateTimeImmutable('2026-01-15T10:30:00'),
        )->toArray();

        $this->assertNull($result['QuotedDeliveryDate']);
    }

    /*
    |--------------------------------------------------------------------------
    | toArray — API key names
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_uses_correct_api_keys(): void
    {
        $result = CreatePurchaseOrderInitialRequest::fromCommand(
            $this->makeCommand(),
            PurchaseOrderReference::fromString('PO1234567890'),
            new DateTimeImmutable('2026-01-15T10:30:00'),
        )->toArray();

        $this->assertArrayHasKey('fkSupplierId', $result);
        $this->assertArrayHasKey('fkLocationId', $result);
        $this->assertArrayHasKey('ExternalInvoiceNumber', $result);
        $this->assertArrayHasKey('Currency', $result);
        $this->assertArrayHasKey('SupplierReferenceNumber', $result);
        $this->assertArrayHasKey('UnitAmountTaxIncludedType', $result);
        $this->assertArrayHasKey('DateOfPurchase', $result);
        $this->assertArrayHasKey('QuotedDeliveryDate', $result);
        $this->assertArrayHasKey('PostagePaid', $result);
        $this->assertArrayHasKey('ShippingTaxRate', $result);
        $this->assertArrayHasKey('ConversionRate', $result);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function makeCommand(): CreatePurchaseOrderCommand
    {
        return new CreatePurchaseOrderCommand(
            fkSupplierId: new Guid('550e8400-e29b-41d4-a716-446655440000'),
            fkLocationId: new Guid('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            reference: PurchaseOrderReference::fromString('PO1234567890'),
            items: [],
            currency: 'GBP',
            supplierReferenceNumber: 'SUP-REF-001',
            unitAmountTaxIncludedType: 1,
            dateOfPurchase: null,
            postagePaid: Money::exclusive(5.00),
            shippingTaxRate: TaxRate::standard(),
            quotedDeliveryDate: null,
            conversionRate: 1.0,
        );
    }
}
