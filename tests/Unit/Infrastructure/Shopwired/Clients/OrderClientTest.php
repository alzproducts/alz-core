<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Clients;

use App\Domain\Catalog\Order\ValueObjects\Order as DomainOrder;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct as DomainOrderProduct;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Clients\OrderClient;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use DateTimeImmutable;
use Illuminate\Http\Client\Response;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * OrderClient Unit Tests.
 *
 * Tests the ShopWired Orders API client functionality.
 * Covers endpoint routing, response parsing (Standard vs Detail), domain conversion,
 * pagination, and error handling.
 */
#[CoversClass(OrderClient::class)]
#[CoversClass(ShopwiredResponseParserTrait::class)]
final class OrderClientTest extends TestCase
{
    private MockInterface&ShopwiredHttpTransport $transport;

    private OrderClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = Mockery::mock(ShopwiredHttpTransport::class);
        $this->client = new OrderClient($this->transport);
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
     * Generate a realistic order API payload.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function orderPayload(
        int $id,
        int $reference,
        bool $withDetails = false,
        array $overrides = [],
    ): array {
        $base = [
            'id' => $id,
            'reference' => $reference,
            'created' => '2024-01-15T10:30:00+00:00',
            'archived' => false,
            'anonymized' => false,
            'pre_order' => false,
            'payment_method' => 'PayPal',
            'total' => 120.00,
            'sub_total' => 100.00,
            'shipping_total' => 15.00,
            'original_shipping_total' => 15.00,
            'partial_payment_total' => 0.0,
            'package_weight' => null,
            'marketing' => true,
            'comments' => 'Leave with neighbour if not in.',
            'tracking_url' => 'https://tracker.com/123',
            'invoice_url' => 'https://shop.com/invoice/123',
            'transaction_id' => 'txn_12345',
            'referrer_id' => 0,
            'earned_reward_points' => 120.0,
            'line_item_vat_calculation' => true,
            'delivery_date' => null,
            'customer_source' => 'web',
            'status' => self::statusPayload(1, 'Paid'),
            'billing_address' => self::addressPayload('Billing'),
            'shipping_address' => self::addressPayload('Shipping'),
            'tax' => null,
            'customer' => self::customerPayload(42),
            'shipping' => [self::shippingPayload()],
            'discounts' => [self::discountPayload()],
            'fees' => [],
            'refunds' => [],
            'partial_payments' => [],
            'admin_comments' => [],
            'file_archives' => [],
            'products' => $withDetails ? [self::productPayload(101, 'SKU-A')] : null,
            'custom_fields' => $withDetails ? ['gift_message' => 'Happy Birthday!'] : null,
        ];

        return \array_merge($base, $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function statusPayload(int $id, string $name, string $type = 'paid'): array
    {
        return ['id' => $id, 'name' => $name, 'type' => $type, 'sort_order' => 1];
    }

    /**
     * @return array<string, mixed>
     */
    private static function addressPayload(string $namePrefix): array
    {
        return [
            'name' => "{$namePrefix} Person",
            'email_address' => 'test@example.com',
            'telephone' => '01234567890',
            'company_name' => 'Test Corp',
            'address_line_1' => "123 {$namePrefix} Street",
            'address_line_2' => null,
            'address_line_3' => null,
            'city' => 'Testville',
            'province' => null,
            'state' => 'Testshire',
            'postcode' => 'TS1 1ST',
            'country' => 'United Kingdom',
            'country_id' => 1,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerPayload(int $id): array
    {
        return [
            'id' => $id,
            'type' => 1,
            'date_of_birth' => null,
            'device_info' => ['ip_address' => '127.0.0.1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shippingPayload(): array
    {
        return ['id' => 1, 'name' => 'Standard Delivery', 'value' => 15.00, 'vat_rate' => 20.0];
    }

    /**
     * @return array<string, mixed>
     */
    private static function discountPayload(): array
    {
        return [
            'name' => 'WELCOME10',
            'value' => 10.00,
            'type' => 'fixed',
            'code' => 'WELCOME10',
            'voucher_id' => 5,
            'offer_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function productPayload(int $id, string $sku): array
    {
        return [
            'id' => $id,
            'title' => 'Test Product',
            'sku' => $sku,
            'price' => 50.00,
            'price_vat' => 60.00,
            'total' => 100.00,
            'total_vat' => 120.00,
            'original_price' => 50.0,
            'cost_price' => 25.0,
            'quantity' => 2,
            'vat_rate' => 20.0,
            'comments' => 'Product comment',
            'variation' => [['name' => 'Color', 'value' => 'Red']],
            'custom_fields' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | getOrderById() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_order_by_id_calls_correct_endpoint_with_detail_params(): void
    {
        $this->transport
            ->shouldReceive('getResource')
            ->once()
            ->with('Order', 42, 'orders', Mockery::on(function (array $params): bool {
                $this->assertStringContainsString('products', $params['fields']);
                $this->assertStringContainsString('products', $params['embed']);

                return true;
            }))
            ->andReturn($this->mockResponse($this->orderPayload(42, 1042, true)));

        $this->client->getOrderById(42);
    }

    #[Test]
    public function get_order_by_id_returns_fully_hydrated_domain_object(): void
    {
        $payload = $this->orderPayload(42, 1042, true);
        $this->transport
            ->shouldReceive('getResource')
            ->with('Order', 42, 'orders', Mockery::any())
            ->andReturn($this->mockResponse($payload));

        $order = $this->client->getOrderById(42);

        $this->assertInstanceOf(DomainOrder::class, $order);
        $this->assertSame(1042, $order->reference);
        $this->assertSame(120.00, $order->total);
        $this->assertSame(100.00, $order->subTotal);
        $this->assertSame(15.00, $order->shippingTotal);
        $this->assertTrue($order->marketing);
        $this->assertSame('Leave with neighbour if not in.', $order->comments);

        // Verify nested domain objects
        $this->assertSame('Paid', $order->status->name->value);
        $this->assertSame(42, $order->customer->id);
        $this->assertSame('Billing Person', $order->billingAddress->name);
        $this->assertSame('Shipping Person', $order->shippingAddress->name);

        // Verify Detail-mode fields
        $this->assertNotNull($order->products);
        $this->assertCount(1, $order->products);
        $this->assertInstanceOf(DomainOrderProduct::class, $order->products[0]);
        $this->assertSame('SKU-A', $order->products[0]->sku);
        $this->assertSame(['gift_message' => 'Happy Birthday!'], $order->customFields);
    }

    #[Test]
    public function get_order_by_id_throws_on_null_response(): void
    {
        $this->transport
            ->shouldReceive('getResource')
            ->with('Order', 1, 'orders', Mockery::any())
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getOrderById(1);
    }

    #[Test]
    public function get_order_by_id_throws_on_malformed_response(): void
    {
        $malformedPayload = ['id' => 123]; // Missing required fields
        $this->transport
            ->shouldReceive('getResource')
            ->with('Order', 123, 'orders', Mockery::any())
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->getOrderById(123);
    }

    #[Test]
    public function get_order_by_id_propagates_transport_exception(): void
    {
        $this->transport
            ->shouldReceive('getResource')
            ->with('Order', 500, 'orders', Mockery::any())
            ->andThrow(new ExternalServiceUnavailableException('Shopwired'));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->client->getOrderById(500);
    }

    /*
    |--------------------------------------------------------------------------
    | getOrderCount() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_order_count_calls_count_endpoint(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('orders/count')
            ->andReturn($this->mockResponse(['count' => 150]));

        $this->client->getOrderCount();
    }

    #[Test]
    public function get_order_count_returns_integer_from_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/count')
            ->andReturn($this->mockResponse(['count' => 1234]));

        $count = $this->client->getOrderCount();

        $this->assertSame(1234, $count);
    }

    #[Test]
    public function get_order_count_returns_zero_when_no_orders(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/count')
            ->andReturn($this->mockResponse(['count' => 0]));

        $count = $this->client->getOrderCount();

        $this->assertSame(0, $count);
    }

    #[Test]
    #[DataProvider('invalidCountResponses')]
    public function get_order_count_throws_on_invalid_response(mixed $response): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/count')
            ->andReturn($this->mockResponse($response));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getOrderCount();
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
    | getOrderCountByStatus() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_order_count_by_status_calls_count_endpoint_with_status_param(): void
    {
        $statusId = 5;
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('orders/count', ['status' => $statusId])
            ->andReturn($this->mockResponse(['count' => 56]));

        $count = $this->client->getOrderCountByStatus($statusId);

        $this->assertSame(56, $count);
    }

    #[Test]
    #[DataProvider('invalidCountResponses')]
    public function get_order_count_by_status_throws_on_invalid_response(mixed $response): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/count', ['status' => 1])
            ->andReturn($this->mockResponse($response));

        $this->expectException(InvalidApiResponseException::class);

        $this->client->getOrderCountByStatus(1);
    }

    /*
    |--------------------------------------------------------------------------
    | searchOrders() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function search_orders_calls_search_endpoint_with_keywords(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('orders/search', ['keywords' => 'REF123'])
            ->andReturn($this->mockResponse(['items' => []]));

        $this->client->searchOrders('REF123');
    }

    #[Test]
    public function search_orders_parses_wrapped_response_correctly(): void
    {
        $payload = [
            'totalItems' => 2,
            'items' => [
                $this->orderPayload(1, 1001),
                $this->orderPayload(2, 1002),
            ],
        ];
        $this->transport
            ->shouldReceive('get')
            ->with('orders/search', Mockery::any())
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->searchOrders('test');

        $this->assertCount(2, $result);
        $this->assertInstanceOf(DomainOrder::class, $result[0]);
        $this->assertSame(1001, $result[0]->reference);
        $this->assertSame(1002, $result[1]->reference);
    }

    #[Test]
    public function search_orders_returns_empty_array_when_no_matches(): void
    {
        $payload = ['totalItems' => 0, 'items' => []];
        $this->transport
            ->shouldReceive('get')
            ->with('orders/search', Mockery::any())
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->searchOrders('nonexistent');

        $this->assertSame([], $result);
    }

    #[Test]
    public function search_orders_throws_on_non_array_response(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/search', Mockery::any())
            ->andReturn($this->mockResponse(null));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage("Expected wrapped response with 'items' key");

        $this->client->searchOrders('test');
    }

    #[Test]
    public function search_orders_throws_when_items_key_is_not_array(): void
    {
        $this->transport
            ->shouldReceive('get')
            ->with('orders/search', Mockery::any())
            ->andReturn($this->mockResponse(['items' => 'not an array', 'totalItems' => 0]));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage("Expected 'items' to be an array");

        $this->client->searchOrders('test');
    }

    #[Test]
    public function search_orders_returns_domain_objects_with_correct_values(): void
    {
        $payload = [
            'items' => [
                $this->orderPayload(5, 5005, false, [
                    'payment_method' => 'Credit',
                    'total' => 250.00,
                    'marketing' => false,
                ]),
            ],
        ];
        $this->transport
            ->shouldReceive('get')
            ->with('orders/search', Mockery::any())
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->searchOrders('5005');

        $this->assertCount(1, $result);
        $order = $result[0];
        $this->assertSame(5005, $order->reference);
        $this->assertSame(250.00, $order->total);
        $this->assertFalse($order->marketing);
        // Standard mode - no products/customFields
        $this->assertNull($order->products);
        $this->assertNull($order->customFields);
    }

    /*
    |--------------------------------------------------------------------------
    | listOrdersInRange() Tests - STANDARD mode
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_orders_in_range_calls_endpoint_with_standard_params(): void
    {
        $from = new DateTimeImmutable('@100');
        $to = new DateTimeImmutable('@200');

        $this->transport->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::on(function (array $params): bool {
                $this->assertSame(100, $params['from']);
                $this->assertSame(200, $params['to']);
                $this->assertStringNotContainsString('products', $params['fields']);
                $this->assertStringNotContainsString('products', $params['embed']);
                $this->assertStringContainsString('status', $params['embed']);

                return true;
            }))
            ->andReturn($this->mockResponse([]));

        $this->client->listOrdersInRange($from, $to);
    }

    #[Test]
    public function list_orders_in_range_returns_empty_when_no_orders(): void
    {
        $from = new DateTimeImmutable('@1');
        $to = new DateTimeImmutable('@100');

        $this->transport->shouldReceive('get')
            ->with('orders', Mockery::type('array'))
            ->andReturn($this->mockResponse([]));

        $result = $this->client->listOrdersInRange($from, $to);

        $this->assertSame([], $result);
    }

    #[Test]
    public function list_orders_in_range_returns_domain_objects_without_products(): void
    {
        $from = new DateTimeImmutable('@1');
        $to = new DateTimeImmutable('@100');

        // Paginator stops when count(items) < pageSize, so 2 items stops immediately
        $payload = [
            $this->orderPayload(1, 1001, withDetails: false),
            $this->orderPayload(2, 1002, withDetails: false),
        ];
        $this->transport->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::type('array'))
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listOrdersInRange($from, $to);

        $this->assertCount(2, $result);
        $this->assertInstanceOf(DomainOrder::class, $result[0]);
        $this->assertSame(1001, $result[0]->reference);
        $this->assertSame(1002, $result[1]->reference);
        // STANDARD mode - products and customFields are null
        $this->assertNull($result[0]->products);
        $this->assertNull($result[0]->customFields);
    }

    #[Test]
    public function list_orders_in_range_fetches_multiple_pages(): void
    {
        $from = new DateTimeImmutable('@1609459200');
        $to = new DateTimeImmutable('@1612137600');

        // Page 1: exactly 100 items (= pageSize) triggers next page fetch
        $page1 = \array_map(
            fn(int $i) => $this->orderPayload($i, 1000 + $i),
            \range(1, 100),
        );

        // Page 2: 30 items (< pageSize) signals final page
        $page2 = \array_map(
            fn(int $i) => $this->orderPayload($i, 1000 + $i),
            \range(101, 130),
        );

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::on(static fn(array $p): bool => $p['offset'] === 0))
            ->andReturn($this->mockResponse($page1));

        $this->transport
            ->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::on(static fn(array $p): bool => $p['offset'] === 100))
            ->andReturn($this->mockResponse($page2));

        $result = $this->client->listOrdersInRange($from, $to);

        $this->assertCount(130, $result);
        $this->assertSame(1001, $result[0]->reference);
        $this->assertSame(1100, $result[99]->reference);
        $this->assertSame(1101, $result[100]->reference);
        $this->assertSame(1130, $result[129]->reference);
    }

    /*
    |--------------------------------------------------------------------------
    | listOrdersInRangeWithDetails() Tests - DETAIL mode
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function list_orders_in_range_with_details_calls_endpoint_with_detail_params(): void
    {
        $from = new DateTimeImmutable('@100');
        $to = new DateTimeImmutable('@200');

        $this->transport->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::on(function (array $params): bool {
                $this->assertSame(100, $params['from']);
                $this->assertSame(200, $params['to']);
                $this->assertStringContainsString('products', $params['fields']);
                $this->assertStringContainsString('customFields', $params['fields']);
                $this->assertStringContainsString('products', $params['embed']);
                $this->assertStringContainsString('custom_fields', $params['embed']);

                return true;
            }))
            ->andReturn($this->mockResponse([]));

        $this->client->listOrdersInRangeWithDetails($from, $to);
    }

    #[Test]
    public function list_orders_in_range_with_details_returns_domain_objects_with_products(): void
    {
        $from = new DateTimeImmutable('@1');
        $to = new DateTimeImmutable('@100');

        // Single item < pageSize stops pagination immediately
        $payload = [
            $this->orderPayload(1, 1001, withDetails: true),
        ];

        $this->transport->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::type('array'))
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listOrdersInRangeWithDetails($from, $to);

        $this->assertCount(1, $result);
        $order = $result[0];
        $this->assertInstanceOf(DomainOrder::class, $order);
        $this->assertNotNull($order->products);
        $this->assertIsArray($order->customFields);
        $this->assertCount(1, $order->products);
        $this->assertInstanceOf(DomainOrderProduct::class, $order->products[0]);
        $this->assertSame('SKU-A', $order->products[0]->sku);
        $this->assertSame(['gift_message' => 'Happy Birthday!'], $order->customFields);
    }

    #[Test]
    public function list_orders_in_range_with_details_converts_nested_objects_correctly(): void
    {
        $from = new DateTimeImmutable('@1');
        $to = new DateTimeImmutable('@100');

        $payload = [
            $this->orderPayload(1, 1001, withDetails: true, overrides: [
                'discounts' => [
                    self::discountPayload(),
                    ['name' => 'SUMMER20', 'value' => 20.00, 'type' => 'percent', 'code' => 'SUMMER20', 'voucher_id' => null, 'offer_id' => 10],
                ],
                'shipping' => [
                    ['id' => 1, 'name' => 'Express', 'value' => 25.00, 'vat_rate' => 20.0],
                ],
            ]),
        ];

        $this->transport->shouldReceive('get')
            ->once()
            ->with('orders', Mockery::type('array'))
            ->andReturn($this->mockResponse($payload));

        $result = $this->client->listOrdersInRangeWithDetails($from, $to);

        $order = $result[0];

        // Verify discounts conversion
        $this->assertCount(2, $order->discounts);
        $this->assertSame('WELCOME10', $order->discounts[0]->name);
        $this->assertSame(10.00, $order->discounts[0]->value);
        $this->assertSame('SUMMER20', $order->discounts[1]->name);
        $this->assertSame(20.00, $order->discounts[1]->value);

        // Verify shipping conversion
        $this->assertNotNull($order->shipping);
        $this->assertSame('Express', $order->shipping->name);
        $this->assertSame(25.00, $order->shipping->value);
    }

    #[Test]
    public function list_orders_in_range_throws_on_malformed_order_data(): void
    {
        $from = new DateTimeImmutable('@1');
        $to = new DateTimeImmutable('@100');

        $malformedPayload = [
            ['id' => 1, 'reference' => 1001], // Missing most required fields
        ];

        $this->transport->shouldReceive('get')
            ->once()
            ->andReturn($this->mockResponse($malformedPayload));

        $this->expectException(InvalidApiResponseException::class);
        $this->expectExceptionMessage('API returned invalid data structure');

        $this->client->listOrdersInRange($from, $to);
    }
}
