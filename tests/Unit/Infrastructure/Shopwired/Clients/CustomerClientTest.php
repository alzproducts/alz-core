<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Clients\CustomerClient;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * CustomerClient Unit Tests.
 *
 * Tests the ShopWired Customers API client functionality.
 * Covers endpoint routing, response parsing, domain conversion, and pagination.
 */
#[CoversClass(CustomerClient::class)]
#[CoversClass(ShopwiredResponseParserTrait::class)]
final class CustomerClientTest extends TestCase
{
    private MockInterface&ShopwiredHttpTransport $transport;

    private CustomerClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredHttpTransport::class);
        $this->client = new CustomerClient($this->transport);
    }

    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a mock Response returning the given JSON data.
     *
     * @param array<mixed>|null $data
     */
    private function mockResponse(?array $data): MockInterface&Response
    {
        $response = Mockery::mock(Response::class);
        $response->shouldReceive('json')->andReturn($data);

        return $response;
    }

    /**
     * Generate a realistic customer API payload.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function customerPayload(
        int $id,
        string $email,
        string $firstName,
        string $lastName,
        bool $isTrade = false,
        array $overrides = [],
    ): array {
        $basePayload = [
            'id' => $id,
            'created_at' => '2024-01-15T10:30:00+00:00',
            'trade_group_id' => null,
            'admin_created' => false,
            'auto_created' => false,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => null,
            'trade' => $isTrade,
            'active' => true,
            'credit_enabled' => false,
            'discount' => 0.0,
            'cost_price_multiplier' => 1.0,
            'phone' => '01234567890',
            'mobile_phone' => null,
            'website' => null,
            'vat_number' => null,
            'accepts_marketing' => true,
            'address_line_1' => '123 Test St',
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => 'Testville',
            'province' => 'Testshire',
            'postcode' => 'TS1 1ST',
            'reward_points' => 10,
            'notes' => 'Some notes for the customer.',
            'country' => ['name' => 'United Kingdom', 'iso' => 'GB'],
            'state' => ['name' => 'London'],
            'wishlists' => [],
            'custom_fields' => ['member_id' => "CUST{$id}"],
        ];

        return \array_merge($basePayload, $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | listCustomers() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_customers_calls_correct_endpoint(): void
    {
        $payload = [
            $this->customerPayload(1, 'test1@example.com', 'John', 'Doe'),
            $this->customerPayload(2, 'test2@example.com', 'Jane', 'Smith'),
        ];

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers')
            ->andReturn($this->mockResponse($payload));

        $this->client->listCustomers();
    }

    #[Test]
    public function list_customers_returns_domain_objects_with_correct_values(): void
    {
        $payload = [
            $this->customerPayload(1, 'john.doe@example.com', 'John', 'Doe', true, [
                'company_name' => 'Acme Corp',
                'discount' => 5.0,
                'cost_price_multiplier' => 0.9,
                'vat_number' => 'GB123456789',
                'custom_fields' => ['membership_tier' => 'Gold'],
                'address_line_1' => '10 Downing St',
                'city' => 'London',
                'country' => ['name' => 'United Kingdom', 'iso' => 'GB'],
                'state' => ['name' => 'Greater London'],
            ]),
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('customers')
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listCustomers();

        $this->assertCount(1, $result);
        $customer = $result[0];
        $this->assertInstanceOf(DomainCustomer::class, $customer);
        $this->assertSame('john.doe@example.com', $customer->email);
        $this->assertSame('John', $customer->firstName);
        $this->assertSame('Doe', $customer->lastName);
        $this->assertSame('Acme Corp', $customer->companyName);
        $this->assertTrue($customer->isTrade);
        $this->assertTrue($customer->isActive);
        $this->assertFalse($customer->creditEnabled);
        $this->assertSame(5.0, $customer->discount);
        $this->assertSame(0.9, $customer->costPriceMultiplier);
        $this->assertSame('GB123456789', $customer->vatNumber);
        $this->assertSame(['membership_tier' => 'Gold'], $customer->customFields);

        // Test address conversion
        $this->assertNotNull($customer->address);
        $this->assertInstanceOf(CustomerAddress::class, $customer->address);
        $this->assertSame('10 Downing St', $customer->address->line1);
        $this->assertSame('London', $customer->address->city);
        $this->assertNotNull($customer->address->country);
        $this->assertSame('United Kingdom', $customer->address->country->name);
        $this->assertNotNull($customer->address->state);
        $this->assertSame('Greater London', $customer->address->state->name);
    }

    #[Test]
    public function list_customers_returns_empty_array_when_api_returns_empty(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers')
            ->andReturn($this->mockResponse([]));

        $result = $this->client->listCustomers();

        $this->assertSame([], $result);
    }

    #[Test]
    public function list_customers_throws_on_non_array_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers')
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->listCustomers();
    }

    #[Test]
    public function list_customers_throws_on_malformed_array_items(): void
    {
        $malformedPayload = [
            ['id' => 1, 'first_name' => 'John', 'last_name' => 'Doe'], // Missing email and other required fields
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('customers')
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->listCustomers();
    }

    /*
    |--------------------------------------------------------------------------
    | getCustomerById() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_customer_by_id_calls_correct_endpoint_with_id(): void
    {
        $customerId = 42;
        $payload = $this->customerPayload($customerId, 'customer@example.com', 'Test', 'User');

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/42')
            ->andReturn($this->mockResponse($payload));

        $this->client->getCustomerById($customerId);
    }

    #[Test]
    public function get_customer_by_id_returns_domain_object_with_all_fields(): void
    {
        $payload = $this->customerPayload(99, 'full.customer@example.com', 'Full', 'Customer', true, [
            'company_name' => 'MegaCorp',
            'active' => false,
            'credit_enabled' => true,
            'discount' => 10.5,
            'cost_price_multiplier' => 0.85,
            'phone' => '02071234567',
            'mobile_phone' => '07700123456',
            'website' => 'https://megacorp.com',
            'vat_number' => 'GB987654321',
            'accepts_marketing' => false,
            'address_line_1' => '789 Business Park',
            'address_line_2' => 'Suite A',
            'address_line_3' => null,
            'city' => 'Industria',
            'province' => 'Manufacturia',
            'postcode' => 'IN1 2DS',
            'reward_points' => 500,
            'notes' => 'VIP client.',
            'country' => ['name' => 'France', 'iso' => 'FR'],
            'state' => null,
            'custom_fields' => ['employee_count' => 500, 'account_manager' => 'Alice'],
        ]);

        $this->transport
            ->shouldReceive('get')
            ->with('customers/99')
            ->andReturn($this->mockResponse($payload));

        $customer = $this->client->getCustomerById(99);

        $this->assertInstanceOf(DomainCustomer::class, $customer);
        $this->assertSame('full.customer@example.com', $customer->email);
        $this->assertSame('Full', $customer->firstName);
        $this->assertSame('Customer', $customer->lastName);
        $this->assertSame('MegaCorp', $customer->companyName);
        $this->assertTrue($customer->isTrade);
        $this->assertFalse($customer->isActive);
        $this->assertTrue($customer->creditEnabled);
        $this->assertSame(10.5, $customer->discount);
        $this->assertSame(0.85, $customer->costPriceMultiplier);
        $this->assertSame('02071234567', $customer->phone);
        $this->assertSame('07700123456', $customer->mobilePhone);
        $this->assertSame('https://megacorp.com', $customer->website);
        $this->assertSame('GB987654321', $customer->vatNumber);
        $this->assertFalse($customer->acceptsMarketing);
        $this->assertSame(500, $customer->rewardPoints);
        $this->assertSame('VIP client.', $customer->notes);
        $this->assertSame(['employee_count' => 500, 'account_manager' => 'Alice'], $customer->customFields);

        $this->assertNotNull($customer->address);
        $this->assertSame('789 Business Park', $customer->address->line1);
        $this->assertSame('Suite A', $customer->address->line2);
        $this->assertNull($customer->address->line3);
        $this->assertSame('Industria', $customer->address->city);
        $this->assertNotNull($customer->address->country);
        $this->assertSame('France', $customer->address->country->name);
        $this->assertNull($customer->address->state);
    }

    #[Test]
    public function get_customer_by_id_returns_object_with_null_address_when_no_address_data(): void
    {
        $payload = $this->customerPayload(1, 'noaddress@example.com', 'No', 'Address', false, [
            'address_line_1' => null,
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => null,
            'province' => null,
            'postcode' => null,
            'country' => null,
            'state' => null,
        ]);

        $this->transport
            ->shouldReceive('get')
            ->with('customers/1')
            ->andReturn($this->mockResponse($payload));

        $customer = $this->client->getCustomerById(1);

        $this->assertInstanceOf(DomainCustomer::class, $customer);
        $this->assertNull($customer->address);
        $this->assertFalse($customer->hasShippableAddress());
    }

    #[Test]
    public function get_customer_by_id_propagates_transport_exception(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/404')
            ->andThrow(new ExternalServiceUnavailableException('Shopwired'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->getCustomerById(404);
    }

    #[Test]
    public function get_customer_by_id_throws_on_non_array_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/1')
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getCustomerById(1);
    }

    #[Test]
    public function get_customer_by_id_throws_on_malformed_single_response(): void
    {
        $malformedPayload = [
            'first_name' => 'Incomplete',
            'last_name' => 'Customer',
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('customers/1')
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->getCustomerById(1);
    }

    /*
    |--------------------------------------------------------------------------
    | getCustomerCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_customer_count_calls_count_endpoint(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 150]));

        $this->client->getCustomerCount();
    }

    #[Test]
    public function get_customer_count_returns_integer_from_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 42]));

        $result = $this->client->getCustomerCount();

        $this->assertSame(42, $result);
    }

    #[Test]
    public function get_customer_count_returns_zero_when_no_customers(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 0]));

        $result = $this->client->getCustomerCount();

        $this->assertSame(0, $result);
    }

    #[Test]
    #[DataProvider('invalidCountResponses')]
    public function get_customer_count_throws_on_invalid_response(mixed $response): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/count')
            ->andReturn($this->mockResponse($response));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getCustomerCount();
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidCountResponses(): array
    {
        return [
            'null response' => [null],
            'empty array' => [[]],
            'missing count key' => [['total' => 5]],
            'count is null' => [['count' => null]],
            'count is string' => [['count' => '42']],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | getTradeCustomerCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_trade_customer_count_calls_count_endpoint_with_trade_param(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count', ['trade' => '1'])
            ->andReturn($this->mockResponse(['count' => 15]));

        $this->client->getTradeCustomerCount();
    }

    #[Test]
    public function get_trade_customer_count_returns_integer_from_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/count', ['trade' => '1'])
            ->andReturn($this->mockResponse(['count' => 5]));

        $result = $this->client->getTradeCustomerCount();

        $this->assertSame(5, $result);
    }

    #[Test]
    #[DataProvider('invalidCountResponses')]
    public function get_trade_customer_count_throws_on_invalid_response(mixed $response): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('customers/count', ['trade' => '1'])
            ->andReturn($this->mockResponse($response));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getTradeCustomerCount();
    }

    /*
    |--------------------------------------------------------------------------
    | searchByEmail() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function search_by_email_calls_correct_endpoint_with_email_and_count_params(): void
    {
        $email = 'search@example.com';
        $payload = [$this->customerPayload(1, $email, 'Searched', 'Customer')];

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', ['count' => 1, 'offset' => 0, 'email' => $email])
            ->andReturn($this->mockResponse($payload));

        $this->client->searchByEmail($email);
    }

    #[Test]
    public function search_by_email_returns_customer_when_found(): void
    {
        $email = 'found@example.com';
        $payload = [$this->customerPayload(5, $email, 'Found', 'One')];

        $this->transport
            ->shouldReceive('get')
            ->with('customers', Mockery::type('array'))
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->searchByEmail($email);

        $this->assertInstanceOf(DomainCustomer::class, $result);
        $this->assertSame($email, $result->email);
        $this->assertSame('Found', $result->firstName);
    }

    #[Test]
    public function search_by_email_returns_null_when_not_found(): void
    {
        $email = 'notfound@example.com';

        $this->transport
            ->shouldReceive('get')
            ->with('customers', Mockery::type('array'))
            ->andReturn($this->mockResponse([]));

        $result = $this->client->searchByEmail($email);

        $this->assertNull($result);
    }

    #[Test]
    public function search_by_email_returns_first_customer_if_api_returns_multiple(): void
    {
        $email = 'multiple@example.com';
        $payload = [
            $this->customerPayload(1, $email, 'First', 'Match'),
            $this->customerPayload(2, $email, 'Second', 'Match'),
        ];

        $this->transport
            ->shouldReceive('get')
            ->with('customers', Mockery::type('array'))
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->searchByEmail($email);

        $this->assertInstanceOf(DomainCustomer::class, $result);
        $this->assertSame('First', $result->firstName);
    }

    #[Test]
    public function search_by_email_throws_on_non_array_response(): void
    {
        $email = 'error@example.com';

        $this->transport
            ->shouldReceive('get')
            ->with('customers', Mockery::type('array'))
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->searchByEmail($email);
    }

    /*
    |--------------------------------------------------------------------------
    | listAllCustomers() Tests - Pagination
    |--------------------------------------------------------------------------
    */

    private const array DEFAULT_BULK_EMBEDS = ['country', 'state', 'wishlists', 'custom_fields'];

    private const array DEFAULT_BULK_FIELDS = [
        'id', 'createdAt', 'tradeGroupId', 'adminCreated', 'autoCreated', 'email', 'firstName', 'lastName',
        'companyName', 'trade', 'active', 'creditEnabled', 'discount', 'costPriceMultiplier', 'phone',
        'mobilePhone', 'website', 'vatNumber', 'acceptsMarketing', 'addressLine1', 'addressLine2', 'addressLine3',
        'city', 'province', 'postcode', 'rewardPoints', 'notes', 'country', 'state', 'wishlists', 'customFields',
    ];

    #[Test]
    public function list_all_customers_sends_correct_pagination_params(): void
    {
        $expectedEmbeds = \implode(',', self::DEFAULT_BULK_EMBEDS);
        $expectedFields = \implode(',', self::DEFAULT_BULK_FIELDS);

        // First call: getCustomerCount
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 0]));

        // Second call: fetch page (even with 0 count, paginator makes initial call)
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 0,
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse([]));

        $this->client->listAllCustomers();
    }

    #[Test]
    public function list_all_customers_returns_empty_when_first_page_empty(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 0]));

        $this->transport
            ->shouldReceive('get')
            ->with('customers', Mockery::type('array'))
            ->andReturn($this->mockResponse([]));

        $result = $this->client->listAllCustomers();

        $this->assertSame([], $result);
    }

    #[Test]
    public function list_all_customers_returns_single_page_when_under_page_size(): void
    {
        $payload = [
            $this->customerPayload(1, 'cust1@example.com', 'One', 'Customer'),
            $this->customerPayload(2, 'cust2@example.com', 'Two', 'Customer'),
        ];

        $expectedEmbeds = \implode(',', self::DEFAULT_BULK_EMBEDS);
        $expectedFields = \implode(',', self::DEFAULT_BULK_FIELDS);

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 2]));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 0,
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listAllCustomers();

        $this->assertCount(2, $result);
        $this->assertSame('One', $result[0]->firstName);
        $this->assertSame('Two', $result[1]->firstName);
    }

    #[Test]
    public function list_all_customers_fetches_multiple_pages(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count')
            ->andReturn($this->mockResponse(['count' => 130]));

        $page1 = \array_map(
            fn(int $i) => $this->customerPayload($i, "cust{$i}@example.com", "First {$i}", "Last {$i}"),
            \range(1, 100),
        );

        $page2 = \array_map(
            fn(int $i) => $this->customerPayload($i, "cust{$i}@example.com", "First {$i}", "Last {$i}"),
            \range(101, 130),
        );

        $expectedEmbeds = \implode(',', self::DEFAULT_BULK_EMBEDS);
        $expectedFields = \implode(',', self::DEFAULT_BULK_FIELDS);

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 0,
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse($page1));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 100,
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse($page2));

        $result = $this->client->listAllCustomers();

        $this->assertCount(130, $result);
        $this->assertSame('First 1', $result[0]->firstName);
        $this->assertSame('First 100', $result[99]->firstName);
        $this->assertSame('First 101', $result[100]->firstName);
        $this->assertSame('First 130', $result[129]->firstName);
    }

    /*
    |--------------------------------------------------------------------------
    | listAllTradeCustomers() Tests - Pagination with Trade Filter
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_all_trade_customers_sends_correct_params_with_trade_filter(): void
    {
        $expectedEmbeds = \implode(',', self::DEFAULT_BULK_EMBEDS);
        $expectedFields = \implode(',', self::DEFAULT_BULK_FIELDS);

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count', ['trade' => '1'])
            ->andReturn($this->mockResponse(['count' => 0]));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 0,
                'trade' => '1',
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse([]));

        $this->client->listAllTradeCustomers();
    }

    #[Test]
    public function list_all_trade_customers_fetches_multiple_pages(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers/count', ['trade' => '1'])
            ->andReturn($this->mockResponse(['count' => 130]));

        $page1 = \array_map(
            fn(int $i) => $this->customerPayload($i, "trade{$i}@example.com", "Trade {$i}", 'User', true),
            \range(1, 100),
        );

        $page2 = \array_map(
            fn(int $i) => $this->customerPayload($i, "trade{$i}@example.com", "Trade {$i}", 'User', true),
            \range(101, 130),
        );

        $expectedEmbeds = \implode(',', self::DEFAULT_BULK_EMBEDS);
        $expectedFields = \implode(',', self::DEFAULT_BULK_FIELDS);

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 0,
                'trade' => '1',
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse($page1));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('customers', [
                'count' => 100,
                'offset' => 100,
                'trade' => '1',
                'embed' => $expectedEmbeds,
                'fields' => $expectedFields,
            ])
            ->andReturn($this->mockResponse($page2));

        $result = $this->client->listAllTradeCustomers();

        $this->assertCount(130, $result);
        $this->assertSame('Trade 1', $result[0]->firstName);
        $this->assertTrue($result[0]->isTrade);
        $this->assertSame('Trade 130', $result[129]->firstName);
    }
}
