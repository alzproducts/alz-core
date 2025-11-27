<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customer\ValueObjects;

use App\Domain\Customer\ValueObjects\CustomerAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CustomerAddress Value Object Unit Tests.
 *
 * Tests the CustomerAddress domain value object including
 * isShippable() and isEmpty() business logic methods.
 */
#[CoversClass(CustomerAddress::class)]
final class CustomerAddressTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_address_with_all_null_fields(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: null,
            province: null,
            postcode: null,
        );

        $this->assertNull($address->line1);
        $this->assertNull($address->line2);
        $this->assertNull($address->line3);
        $this->assertNull($address->city);
        $this->assertNull($address->province);
        $this->assertNull($address->postcode);
    }

    #[Test]
    public function it_creates_address_with_all_fields(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: 'Apt 4B',
            line3: 'Building C',
            city: 'London',
            province: 'Greater London',
            postcode: 'SW1A 1AA',
        );

        $this->assertSame('123 Main Street', $address->line1);
        $this->assertSame('Apt 4B', $address->line2);
        $this->assertSame('Building C', $address->line3);
        $this->assertSame('London', $address->city);
        $this->assertSame('Greater London', $address->province);
        $this->assertSame('SW1A 1AA', $address->postcode);
    }

    /*
    |--------------------------------------------------------------------------
    | isShippable() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_shippable_returns_true_with_minimum_required_fields(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertTrue($address->isShippable());
    }

    #[Test]
    public function is_shippable_returns_false_when_line1_is_null(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertFalse($address->isShippable());
    }

    #[Test]
    public function is_shippable_returns_false_when_city_is_null(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: null,
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertFalse($address->isShippable());
    }

    #[Test]
    public function is_shippable_returns_false_when_postcode_is_null(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: null,
        );

        $this->assertFalse($address->isShippable());
    }

    #[Test]
    public function is_shippable_does_not_require_line2_or_line3(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertTrue($address->isShippable());
    }

    #[Test]
    public function is_shippable_does_not_require_province(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertTrue($address->isShippable());
    }

    /*
    |--------------------------------------------------------------------------
    | isEmpty() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_empty_returns_true_when_all_fields_are_null(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: null,
            province: null,
            postcode: null,
        );

        $this->assertTrue($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_line1_is_set(): void
    {
        $address = new CustomerAddress(
            line1: '123 Main Street',
            line2: null,
            line3: null,
            city: null,
            province: null,
            postcode: null,
        );

        $this->assertFalse($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_line2_is_set(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: 'Apt 4B',
            line3: null,
            city: null,
            province: null,
            postcode: null,
        );

        $this->assertFalse($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_line3_is_set(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: 'Building C',
            city: null,
            province: null,
            postcode: null,
        );

        $this->assertFalse($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_city_is_set(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: null,
        );

        $this->assertFalse($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_province_is_set(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: null,
            province: 'Greater London',
            postcode: null,
        );

        $this->assertFalse($address->isEmpty());
    }

    #[Test]
    public function is_empty_returns_false_when_postcode_is_set(): void
    {
        $address = new CustomerAddress(
            line1: null,
            line2: null,
            line3: null,
            city: null,
            province: null,
            postcode: 'SW1A 1AA',
        );

        $this->assertFalse($address->isEmpty());
    }
}
