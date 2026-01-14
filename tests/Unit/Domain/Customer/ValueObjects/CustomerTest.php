<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customer\ValueObjects;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Customer Value Object Unit Tests.
 *
 * Tests the Customer domain value object including assertions,
 * computed properties, and business logic methods.
 */
#[CoversClass(Customer::class)]
final class CustomerTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid customer with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createCustomer(array $overrides = []): Customer
    {
        $defaults = [
            'id' => 12345,
            'createdAt' => new DateTimeImmutable('2024-01-15T10:30:00Z'),
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'companyName' => null,
            'isTrade' => false,
            'isActive' => true,
            'isCreditEnabled' => false,
            'phone' => null,
            'mobilePhone' => null,
            'acceptsMarketing' => true,
            'address' => null,
            'notes' => null,
            'customFields' => [],
        ];

        $data = \array_merge($defaults, $overrides);

        return new Customer(...$data);
    }

    /**
     * Create a shippable address.
     */
    private function createShippableAddress(): CustomerAddress
    {
        return new CustomerAddress(
            line1: '123 Test Street',
            line2: null,
            line3: null,
            city: 'London',
            province: null,
            postcode: 'SW1A 1AA',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_customer_with_minimum_required_fields(): void
    {
        $customer = $this->createCustomer();

        $this->assertSame('test@example.com', $customer->email);
        $this->assertSame('John', $customer->firstName);
        $this->assertSame('Doe', $customer->lastName);
    }

    #[Test]
    public function it_creates_customer_with_all_fields(): void
    {
        $address = $this->createShippableAddress();

        $customer = $this->createCustomer([
            'companyName' => 'Acme Corp',
            'isTrade' => true,
            'isActive' => false,
            'isCreditEnabled' => true,
            'phone' => '020 1234 5678',
            'mobilePhone' => '07700 123456',
            'acceptsMarketing' => false,
            'address' => $address,
            'notes' => 'VIP customer',
            'customFields' => ['tier' => 'gold'],
        ]);

        $this->assertSame('Acme Corp', $customer->companyName);
        $this->assertTrue($customer->isTrade);
        $this->assertFalse($customer->isActive);
        $this->assertTrue($customer->isCreditEnabled);
        $this->assertSame('020 1234 5678', $customer->phone);
        $this->assertSame('07700 123456', $customer->mobilePhone);
        $this->assertFalse($customer->acceptsMarketing);
        $this->assertSame($address, $customer->address);
        $this->assertSame('VIP customer', $customer->notes);
        $this->assertSame(['tier' => 'gold'], $customer->customFields);
    }

    /*
    |--------------------------------------------------------------------------
    | fullName() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function full_name_combines_first_and_last_name(): void
    {
        $customer = $this->createCustomer([
            'firstName' => 'John',
            'lastName' => 'Doe',
        ]);

        $this->assertSame('John Doe', $customer->fullName());
    }

    #[Test]
    public function full_name_trims_outer_whitespace(): void
    {
        // fullName() trims the combined result, but preserves internal whitespace
        // "  John  " + " " + "  Doe  " = "  John     Doe  " → mb_trim → "John     Doe"
        $customer = $this->createCustomer([
            'firstName' => '  John  ',
            'lastName' => '  Doe  ',
        ]);

        $this->assertSame('John     Doe', $customer->fullName());
    }

    #[Test]
    #[DataProvider('fullNameDataProvider')]
    public function full_name_handles_various_inputs(string $firstName, string $lastName, string $expected): void
    {
        $customer = $this->createCustomer([
            'firstName' => $firstName,
            'lastName' => $lastName,
        ]);

        $this->assertSame($expected, $customer->fullName());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function fullNameDataProvider(): array
    {
        return [
            'simple names' => ['John', 'Doe', 'John Doe'],
            'hyphenated last name' => ['Mary', 'Smith-Jones', 'Mary Smith-Jones'],
            'multi-part first name' => ['Mary Jane', 'Watson', 'Mary Jane Watson'],
            'single character names' => ['J', 'D', 'J D'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | hasShippableAddress() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_shippable_address_returns_false_when_address_is_null(): void
    {
        $customer = $this->createCustomer(['address' => null]);

        $this->assertFalse($customer->hasShippableAddress());
    }

    #[Test]
    public function has_shippable_address_returns_false_when_address_not_shippable(): void
    {
        $incompleteAddress = new CustomerAddress(
            line1: '123 Test St',
            line2: null,
            line3: null,
            city: null,  // Missing city
            province: null,
            postcode: null,  // Missing postcode
        );

        $customer = $this->createCustomer(['address' => $incompleteAddress]);

        $this->assertFalse($customer->hasShippableAddress());
    }

    #[Test]
    public function has_shippable_address_returns_true_when_address_is_shippable(): void
    {
        $customer = $this->createCustomer([
            'address' => $this->createShippableAddress(),
        ]);

        $this->assertTrue($customer->hasShippableAddress());
    }
}
