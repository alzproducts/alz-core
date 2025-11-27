<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Customer\ValueObjects;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Domain\ValueObjects\Country;
use InvalidArgumentException;
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
            'email' => 'test@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'companyName' => null,
            'isTrade' => false,
            'isActive' => true,
            'creditEnabled' => false,
            'discount' => 0.0,
            'costPriceMultiplier' => 1.0,
            'phone' => null,
            'mobilePhone' => null,
            'website' => null,
            'vatNumber' => null,
            'acceptsMarketing' => true,
            'address' => null,
            'rewardPoints' => 0,
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
            country: new Country(name: 'United Kingdom', iso: 'GB'),
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
            'creditEnabled' => true,
            'discount' => 15.5,
            'costPriceMultiplier' => 0.85,
            'phone' => '020 1234 5678',
            'mobilePhone' => '07700 123456',
            'website' => 'https://acme.com',
            'vatNumber' => 'GB123456789',
            'acceptsMarketing' => false,
            'address' => $address,
            'rewardPoints' => 500,
            'notes' => 'VIP customer',
            'customFields' => ['tier' => 'gold'],
        ]);

        $this->assertSame('Acme Corp', $customer->companyName);
        $this->assertTrue($customer->isTrade);
        $this->assertFalse($customer->isActive);
        $this->assertTrue($customer->creditEnabled);
        $this->assertSame(15.5, $customer->discount);
        $this->assertSame(0.85, $customer->costPriceMultiplier);
        $this->assertSame('020 1234 5678', $customer->phone);
        $this->assertSame('07700 123456', $customer->mobilePhone);
        $this->assertSame('https://acme.com', $customer->website);
        $this->assertSame('GB123456789', $customer->vatNumber);
        $this->assertFalse($customer->acceptsMarketing);
        $this->assertSame($address, $customer->address);
        $this->assertSame(500, $customer->rewardPoints);
        $this->assertSame('VIP customer', $customer->notes);
        $this->assertSame(['tier' => 'gold'], $customer->customFields);
    }

    /*
    |--------------------------------------------------------------------------
    | Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_accepts_zero_discount(): void
    {
        $customer = $this->createCustomer(['discount' => 0.0]);

        $this->assertSame(0.0, $customer->discount);
    }

    #[Test]
    public function it_accepts_positive_discount(): void
    {
        $customer = $this->createCustomer(['discount' => 25.0]);

        $this->assertSame(25.0, $customer->discount);
    }

    #[Test]
    public function it_throws_on_negative_discount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Discount cannot be negative');

        $this->createCustomer(['discount' => -1.0]);
    }

    #[Test]
    public function it_accepts_zero_cost_price_multiplier(): void
    {
        $customer = $this->createCustomer(['costPriceMultiplier' => 0.0]);

        $this->assertSame(0.0, $customer->costPriceMultiplier);
    }

    #[Test]
    public function it_accepts_positive_cost_price_multiplier(): void
    {
        $customer = $this->createCustomer(['costPriceMultiplier' => 1.5]);

        $this->assertSame(1.5, $customer->costPriceMultiplier);
    }

    #[Test]
    public function it_throws_on_negative_cost_price_multiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cost price multiplier cannot be negative');

        $this->createCustomer(['costPriceMultiplier' => -0.1]);
    }

    #[Test]
    public function it_accepts_zero_reward_points(): void
    {
        $customer = $this->createCustomer(['rewardPoints' => 0]);

        $this->assertSame(0, $customer->rewardPoints);
    }

    #[Test]
    public function it_accepts_positive_reward_points(): void
    {
        $customer = $this->createCustomer(['rewardPoints' => 1000]);

        $this->assertSame(1000, $customer->rewardPoints);
    }

    #[Test]
    public function it_throws_on_negative_reward_points(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Reward points cannot be negative');

        $this->createCustomer(['rewardPoints' => -1]);
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
            country: null,  // Missing country
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
