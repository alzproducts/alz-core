<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderAddress Value Object Unit Tests.
 *
 * Tests the Domain value object for order billing/shipping addresses.
 * This is a pure data container - tests verify construction and property access.
 */
#[CoversClass(OrderAddress::class)]
final class OrderAddressTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid OrderAddress with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrderAddress(array $overrides = []): OrderAddress
    {
        $defaults = [
            'name' => 'John Doe',
            'emailAddress' => 'john.doe@example.com',
            'telephone' => '01onal234567890',
            'companyName' => 'Acme Corporation',
            'addressLine1' => '123 Test Street',
            'addressLine2' => 'Suite 456',
            'addressLine3' => 'Building B',
            'city' => 'London',
            'province' => 'Greater London',
            'state' => null,
            'postcode' => 'SW1A 1AA',
            'country' => 'United Kingdom',
            'countryId' => 1,
        ];

        $data = \array_merge($defaults, $overrides);

        return new OrderAddress(...$data);
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_an_order_address_with_all_properties(): void
    {
        $address = $this->createOrderAddress();

        $this->assertSame('John Doe', $address->name);
        $this->assertSame('john.doe@example.com', $address->emailAddress);
        $this->assertSame('01onal234567890', $address->telephone);
        $this->assertSame('Acme Corporation', $address->companyName);
        $this->assertSame('123 Test Street', $address->addressLine1);
        $this->assertSame('Suite 456', $address->addressLine2);
        $this->assertSame('Building B', $address->addressLine3);
        $this->assertSame('London', $address->city);
        $this->assertSame('Greater London', $address->province);
        $this->assertNull($address->state);
        $this->assertSame('SW1A 1AA', $address->postcode);
        $this->assertSame('United Kingdom', $address->country);
    }

    /*
    |--------------------------------------------------------------------------
    | Nullable Field Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_null_telephone(): void
    {
        $address = $this->createOrderAddress(['telephone' => null]);

        $this->assertNull($address->telephone);
    }

    #[Test]
    public function it_accepts_null_company_name(): void
    {
        $address = $this->createOrderAddress(['companyName' => null]);

        $this->assertNull($address->companyName);
    }

    #[Test]
    public function it_accepts_null_address_line_2(): void
    {
        $address = $this->createOrderAddress(['addressLine2' => null]);

        $this->assertNull($address->addressLine2);
    }

    #[Test]
    public function it_accepts_null_address_line_3(): void
    {
        $address = $this->createOrderAddress(['addressLine3' => null]);

        $this->assertNull($address->addressLine3);
    }

    #[Test]
    public function it_accepts_null_province(): void
    {
        $address = $this->createOrderAddress(['province' => null]);

        $this->assertNull($address->province);
    }

    #[Test]
    public function it_accepts_null_state(): void
    {
        $address = $this->createOrderAddress(['state' => null]);

        $this->assertNull($address->state);
    }

    #[Test]
    public function it_accepts_all_nullable_fields_as_null(): void
    {
        $address = $this->createOrderAddress([
            'telephone' => null,
            'companyName' => null,
            'addressLine2' => null,
            'addressLine3' => null,
            'province' => null,
            'state' => null,
        ]);

        $this->assertNull($address->telephone);
        $this->assertNull($address->companyName);
        $this->assertNull($address->addressLine2);
        $this->assertNull($address->addressLine3);
        $this->assertNull($address->province);
        $this->assertNull($address->state);
    }

    /*
    |--------------------------------------------------------------------------
    | US Address Tests (State instead of Province)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_handles_us_address_with_state(): void
    {
        $address = $this->createOrderAddress([
            'name' => 'Jane Smith',
            'emailAddress' => 'jane@example.com',
            'addressLine1' => '456 Main Street',
            'city' => 'New York',
            'province' => null,
            'state' => 'NY',
            'postcode' => '10001',
            'country' => 'United States',
        ]);

        $this->assertSame('New York', $address->city);
        $this->assertNull($address->province);
        $this->assertSame('NY', $address->state);
        $this->assertSame('10001', $address->postcode);
        $this->assertSame('United States', $address->country);
    }

    /*
    |--------------------------------------------------------------------------
    | Edge Case Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_international_characters_in_name(): void
    {
        $address = $this->createOrderAddress([
            'name' => 'José García',
            'city' => 'São Paulo',
            'country' => 'Österreich',
        ]);

        $this->assertSame('José García', $address->name);
        $this->assertSame('São Paulo', $address->city);
        $this->assertSame('Österreich', $address->country);
    }

    #[Test]
    public function it_accepts_long_address_lines(): void
    {
        $longAddress = 'This is a very long address line that contains many details about the location including apartment numbers, floor numbers, building names, and other identifying information';

        $address = $this->createOrderAddress(['addressLine1' => $longAddress]);

        $this->assertSame($longAddress, $address->addressLine1);
    }
}
