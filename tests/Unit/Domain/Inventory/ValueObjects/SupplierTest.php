<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\ValueObjects\Supplier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Supplier Value Object Unit Tests.
 *
 * Tests the Domain value object for the Linnworks supplier directory.
 */
#[CoversClass(Supplier::class)]
final class SupplierTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid Supplier with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createSupplier(array $overrides = []): Supplier
    {
        $defaults = [
            'pkSupplierId' => 'b1e4c3a2-5f6d-7e8f-9a0b-1c2d3e4f5a6b',
            'supplierName' => 'Acme Supplies Ltd',
            'contactName' => 'John Smith',
            'address' => '123 High Street',
            'alternativeAddress' => 'Unit 4B',
            'city' => 'Manchester',
            'region' => 'Greater Manchester',
            'country' => 'United Kingdom',
            'postCode' => 'M1 1AA',
            'telephoneNumber' => '0161 123 4567',
            'secondaryTelNumber' => '07700 900000',
            'faxNumber' => '0161 123 4568',
            'email' => 'orders@acme.co.uk',
            'webPage' => 'https://acme.co.uk',
            'currency' => 'GBP',
        ];

        $data = \array_merge($defaults, $overrides);

        return new Supplier(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_a_supplier_with_all_properties(): void
    {
        $supplier = $this->createSupplier();

        $this->assertSame('b1e4c3a2-5f6d-7e8f-9a0b-1c2d3e4f5a6b', $supplier->pkSupplierId);
        $this->assertSame('Acme Supplies Ltd', $supplier->supplierName);
        $this->assertSame('John Smith', $supplier->contactName);
        $this->assertSame('123 High Street', $supplier->address);
        $this->assertSame('Unit 4B', $supplier->alternativeAddress);
        $this->assertSame('Manchester', $supplier->city);
        $this->assertSame('Greater Manchester', $supplier->region);
        $this->assertSame('United Kingdom', $supplier->country);
        $this->assertSame('M1 1AA', $supplier->postCode);
        $this->assertSame('0161 123 4567', $supplier->telephoneNumber);
        $this->assertSame('07700 900000', $supplier->secondaryTelNumber);
        $this->assertSame('0161 123 4568', $supplier->faxNumber);
        $this->assertSame('orders@acme.co.uk', $supplier->email);
        $this->assertSame('https://acme.co.uk', $supplier->webPage);
        $this->assertSame('GBP', $supplier->currency);
    }

    #[Test]
    public function it_creates_a_supplier_with_nullable_fields_as_null(): void
    {
        $supplier = $this->createSupplier([
            'contactName' => null,
            'address' => null,
            'alternativeAddress' => null,
            'city' => null,
            'region' => null,
            'country' => null,
            'postCode' => null,
            'telephoneNumber' => null,
            'secondaryTelNumber' => null,
            'faxNumber' => null,
            'email' => null,
            'webPage' => null,
            'currency' => null,
        ]);

        $this->assertNull($supplier->contactName);
        $this->assertNull($supplier->address);
        $this->assertNull($supplier->alternativeAddress);
        $this->assertNull($supplier->city);
        $this->assertNull($supplier->region);
        $this->assertNull($supplier->country);
        $this->assertNull($supplier->postCode);
        $this->assertNull($supplier->telephoneNumber);
        $this->assertNull($supplier->secondaryTelNumber);
        $this->assertNull($supplier->faxNumber);
        $this->assertNull($supplier->email);
        $this->assertNull($supplier->webPage);
        $this->assertNull($supplier->currency);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_throws_when_supplier_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier ID cannot be empty');

        $this->createSupplier(['pkSupplierId' => '']);
    }

    #[Test]
    public function it_throws_when_supplier_name_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Supplier name cannot be empty');

        $this->createSupplier(['supplierName' => '']);
    }
}
