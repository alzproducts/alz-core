<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Infrastructure\Shopwired\Responses\CustomerResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Customer DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for parsing ShopWired customer API responses.
 * Verifies snake_case mapping, numeric suffix handling, and domain conversion.
 */
#[CoversClass(CustomerResponse::class)]
final class CustomerTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a complete snake_case API payload.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function completePayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 123,
            'created_at' => '2024-03-15T14:30:00+00:00',
            'trade_group_id' => 5,
            'admin_created' => false,
            'auto_created' => true,
            'email' => 'john.doe@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company_name' => 'Acme Corp',
            'trade' => true,
            'active' => true,
            'credit_enabled' => false,
            'discount' => 15.5,
            'cost_price_multiplier' => 0.85,
            'phone' => '020 1234 5678',
            'mobile_phone' => '07700 123456',
            'website' => 'https://acme.com',
            'vat_number' => 'GB123456789',
            'accepts_marketing' => true,
            'address_line_1' => '10 Downing Street',
            'address_line_2' => 'Westminster',
            'address_line_3' => null,
            'city' => 'London',
            'province' => 'Greater London',
            'postcode' => 'SW1A 2AA',
            'reward_points' => 500,
            'notes' => 'VIP customer',
            'wishlists' => [],
            'custom_fields' => ['tier' => 'gold', 'priority' => 'high'],
        ], $overrides);
    }

    /**
     * Create a minimal payload with only required fields (nulls where allowed).
     *
     * @return array<string, mixed>
     */
    private function minimalPayload(): array
    {
        return [
            'id' => 1,
            'created_at' => '2024-01-01T00:00:00+00:00',
            'trade_group_id' => null,
            'admin_created' => false,
            'auto_created' => false,
            'email' => 'minimal@example.com',
            'first_name' => 'Min',
            'last_name' => 'User',
            'company_name' => null,
            'trade' => false,
            'active' => true,
            'credit_enabled' => false,
            'discount' => 0.0,
            'cost_price_multiplier' => 1.0,
            'phone' => null,
            'mobile_phone' => null,
            'website' => null,
            'vat_number' => null,
            'accepts_marketing' => false,
            'address_line_1' => null,
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => null,
            'province' => null,
            'postcode' => null,
            'reward_points' => 0,
            'notes' => null,
            'wishlists' => [],
            'custom_fields' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | from() Parsing Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_maps_snake_case_to_camel_case_properties(): void
    {
        $payload = $this->completePayload();

        $customer = CustomerResponse::from($payload);

        $this->assertSame(123, $customer->id);
        $this->assertSame('2024-03-15T14:30:00+00:00', $customer->createdAt);
        $this->assertSame(5, $customer->tradeGroupId);
        $this->assertFalse($customer->adminCreated);
        $this->assertTrue($customer->autoCreated);
    }

    #[Test]
    public function from_maps_numeric_suffix_address_fields(): void
    {
        $payload = $this->completePayload();

        $customer = CustomerResponse::from($payload);

        // These require explicit #[MapInputName] attributes
        $this->assertSame('10 Downing Street', $customer->addressLine1);
        $this->assertSame('Westminster', $customer->addressLine2);
        $this->assertNull($customer->addressLine3);
    }

    #[Test]
    public function from_parses_boolean_fields_correctly(): void
    {
        $payload = $this->completePayload([
            'trade' => true,
            'active' => false,
            'credit_enabled' => true,
            'accepts_marketing' => false,
            'admin_created' => true,
            'auto_created' => false,
        ]);

        $customer = CustomerResponse::from($payload);

        $this->assertTrue($customer->trade);
        $this->assertFalse($customer->active);
        $this->assertTrue($customer->creditEnabled);
        $this->assertFalse($customer->acceptsMarketing);
        $this->assertTrue($customer->adminCreated);
        $this->assertFalse($customer->autoCreated);
    }

    #[Test]
    public function from_parses_float_fields_correctly(): void
    {
        $payload = $this->completePayload([
            'discount' => 25.75,
            'cost_price_multiplier' => 0.65,
        ]);

        $customer = CustomerResponse::from($payload);

        $this->assertSame(25.75, $customer->discount);
        $this->assertSame(0.65, $customer->costPriceMultiplier);
    }

    #[Test]
    public function from_handles_null_optional_fields(): void
    {
        $payload = $this->minimalPayload();

        $customer = CustomerResponse::from($payload);

        $this->assertNull($customer->tradeGroupId);
        $this->assertNull($customer->companyName);
        $this->assertNull($customer->phone);
        $this->assertNull($customer->mobilePhone);
        $this->assertNull($customer->website);
        $this->assertNull($customer->vatNumber);
        $this->assertNull($customer->notes);
    }

    #[Test]
    public function from_handles_all_address_fields_null(): void
    {
        $payload = $this->minimalPayload();

        $customer = CustomerResponse::from($payload);

        $this->assertNull($customer->addressLine1);
        $this->assertNull($customer->addressLine2);
        $this->assertNull($customer->addressLine3);
        $this->assertNull($customer->city);
        $this->assertNull($customer->province);
        $this->assertNull($customer->postcode);
    }

    #[Test]
    public function from_parses_custom_fields_as_array(): void
    {
        $payload = $this->completePayload([
            'custom_fields' => ['key1' => 'value1', 'nested' => ['a' => 1]],
        ]);

        $customer = CustomerResponse::from($payload);

        $this->assertSame(['key1' => 'value1', 'nested' => ['a' => 1]], $customer->customFields);
    }

    #[Test]
    public function from_parses_empty_wishlists_array(): void
    {
        $payload = $this->completePayload(['wishlists' => []]);

        $customer = CustomerResponse::from($payload);

        $this->assertSame([], $customer->wishlists);
    }

    #[Test]
    public function from_parses_wishlists_with_items(): void
    {
        $payload = $this->completePayload([
            'wishlists' => [
                ['id' => 1, 'token' => 'abc12345', 'is_public' => true],
                ['id' => 2, 'token' => 'xyz67890', 'is_public' => false],
            ],
        ]);

        $customer = CustomerResponse::from($payload);

        $this->assertCount(2, $customer->wishlists);
        $this->assertSame(1, $customer->wishlists[0]->id);
        $this->assertSame('abc12345', $customer->wishlists[0]->token);
        $this->assertTrue($customer->wishlists[0]->isPublic);
        $this->assertSame(2, $customer->wishlists[1]->id);
        $this->assertFalse($customer->wishlists[1]->isPublic);
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() Conversion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_returns_domain_customer(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertInstanceOf(DomainCustomer::class, $domain);
    }

    #[Test]
    public function to_domain_maps_identity_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertSame('john.doe@example.com', $domain->email);
        $this->assertSame('John', $domain->firstName);
        $this->assertSame('Doe', $domain->lastName);
        $this->assertSame('Acme Corp', $domain->companyName);
    }

    #[Test]
    public function to_domain_transforms_boolean_field_names(): void
    {
        $customer = CustomerResponse::from($this->completePayload([
            'trade' => true,
            'active' => false,
        ]));

        $domain = $customer->toDomain();

        // DTO uses 'trade', domain uses 'isTrade'
        $this->assertTrue($domain->isTrade);
        // DTO uses 'active', domain uses 'isActive'
        $this->assertFalse($domain->isActive);
    }

    #[Test]
    public function to_domain_maps_pricing_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertSame(15.5, $domain->discount);
        $this->assertSame(0.85, $domain->costPriceMultiplier);
    }

    #[Test]
    public function to_domain_maps_contact_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertSame('020 1234 5678', $domain->phone);
        $this->assertSame('07700 123456', $domain->mobilePhone);
        $this->assertSame('https://acme.com', $domain->website);
        $this->assertSame('GB123456789', $domain->vatNumber);
        $this->assertTrue($domain->acceptsMarketing);
    }

    #[Test]
    public function to_domain_maps_loyalty_and_notes(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertSame(500, $domain->rewardPoints);
        $this->assertSame('VIP customer', $domain->notes);
    }

    #[Test]
    public function to_domain_preserves_custom_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload([
            'custom_fields' => ['tier' => 'platinum'],
        ]));

        $domain = $customer->toDomain();

        $this->assertSame(['tier' => 'platinum'], $domain->customFields);
    }

    #[Test]
    public function to_domain_builds_address_from_flat_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertInstanceOf(CustomerAddress::class, $domain->address);
        $this->assertSame('10 Downing Street', $domain->address->line1);
        $this->assertSame('Westminster', $domain->address->line2);
        $this->assertNull($domain->address->line3);
        $this->assertSame('London', $domain->address->city);
        $this->assertSame('Greater London', $domain->address->province);
        $this->assertSame('SW1A 2AA', $domain->address->postcode);
    }

    #[Test]
    public function to_domain_returns_null_address_when_all_fields_null(): void
    {
        $customer = CustomerResponse::from($this->minimalPayload());

        $domain = $customer->toDomain();

        $this->assertNull($domain->address);
    }

    #[Test]
    public function to_domain_builds_address_with_partial_data(): void
    {
        $payload = $this->minimalPayload();
        $payload['address_line_1'] = '123 Partial Street';
        $payload['postcode'] = 'AB1 2CD';
        // city, province, line2, line3 remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertSame('123 Partial Street', $domain->address->line1);
        $this->assertNull($domain->address->line2);
        $this->assertNull($domain->address->city);
        $this->assertSame('AB1 2CD', $domain->address->postcode);
    }

    #[Test]
    public function to_domain_builds_address_with_only_city(): void
    {
        $payload = $this->minimalPayload();
        $payload['city'] = 'Manchester';
        // all other address fields remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertNull($domain->address->line1);
        $this->assertSame('Manchester', $domain->address->city);
    }

    #[Test]
    public function to_domain_builds_address_with_only_line2(): void
    {
        $payload = $this->minimalPayload();
        $payload['address_line_2'] = 'Apartment 5';
        // line1 and all other address fields remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertNull($domain->address->line1);
        $this->assertSame('Apartment 5', $domain->address->line2);
    }

    #[Test]
    public function to_domain_builds_address_with_only_line3(): void
    {
        $payload = $this->minimalPayload();
        $payload['address_line_3'] = 'Building C';
        // line1, line2 and all other address fields remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertNull($domain->address->line1);
        $this->assertNull($domain->address->line2);
        $this->assertSame('Building C', $domain->address->line3);
    }

    #[Test]
    public function to_domain_builds_address_with_only_province(): void
    {
        $payload = $this->minimalPayload();
        $payload['province'] = 'Yorkshire';
        // all other address fields remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertSame('Yorkshire', $domain->address->province);
    }

    #[Test]
    public function to_domain_builds_address_with_only_postcode(): void
    {
        $payload = $this->minimalPayload();
        $payload['postcode'] = 'AB1 2CD';
        // all other address fields remain null

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        $this->assertNotNull($domain->address);
        $this->assertSame('AB1 2CD', $domain->address->postcode);
    }

    #[Test]
    public function to_domain_creates_new_instance_each_call(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain1 = $customer->toDomain();
        $domain2 = $customer->toDomain();

        $this->assertNotSame($domain1, $domain2);
        $this->assertEquals($domain1, $domain2);
    }

    #[Test]
    public function to_domain_does_not_include_infrastructure_only_fields(): void
    {
        $customer = CustomerResponse::from($this->completePayload());

        $domain = $customer->toDomain();

        // These fields exist on DTO but NOT on domain
        $this->assertSame(123, $customer->id);
        $this->assertSame('2024-03-15T14:30:00+00:00', $customer->createdAt);
        $this->assertSame(5, $customer->tradeGroupId);
        $this->assertFalse($customer->adminCreated);

        // Domain shouldn't have these (verified by not being in DomainCustomer constructor)
        $reflection = new ReflectionClass($domain);
        $this->assertFalse($reflection->hasProperty('id'));
        $this->assertFalse($reflection->hasProperty('createdAt'));
        $this->assertFalse($reflection->hasProperty('tradeGroupId'));
    }

    #[Test]
    public function to_domain_does_not_convert_wishlists(): void
    {
        $payload = $this->completePayload([
            'wishlists' => [
                ['id' => 1, 'token' => 12345, 'is_public' => true],
            ],
        ]);

        $customer = CustomerResponse::from($payload);
        $domain = $customer->toDomain();

        // DTO has wishlists
        $this->assertCount(1, $customer->wishlists);

        // Domain doesn't have wishlists (ShopWired-specific, no business use)
        $reflection = new ReflectionClass($domain);
        $this->assertFalse($reflection->hasProperty('wishlists'));
    }
}
