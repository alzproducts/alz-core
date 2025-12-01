<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\Order as DomainOrder;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress as DomainOrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer as DomainOrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount as DomainOrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct as DomainOrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping as DomainOrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus as DomainOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use App\Infrastructure\Shopwired\Responses\Order;
use App\Infrastructure\Shopwired\Responses\OrderAddress;
use App\Infrastructure\Shopwired\Responses\OrderCustomer;
use App\Infrastructure\Shopwired\Responses\OrderDiscount;
use App\Infrastructure\Shopwired\Responses\OrderProduct;
use App\Infrastructure\Shopwired\Responses\OrderShipping;
use App\Infrastructure\Shopwired\Responses\OrderStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order Response DTO Unit Tests.
 *
 * Tests the Spatie Data DTO for parsing ShopWired order API responses.
 * Verifies snake_case mapping, nested object parsing, and domain conversion.
 */
#[CoversClass(Order::class)]
#[CoversClass(OrderAddress::class)]
#[CoversClass(OrderCustomer::class)]
#[CoversClass(OrderDiscount::class)]
#[CoversClass(OrderProduct::class)]
#[CoversClass(OrderShipping::class)]
#[CoversClass(OrderStatus::class)]
final class OrderTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a complete snake_case API payload for an order.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function completePayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 12345,
            'reference' => 67890,
            'created' => '2024-01-15T10:30:00+00:00',
            'archived' => false,
            'anonymized' => false,
            'pre_order' => false,
            'payment_method' => 'Opayo Hosted',
            'total' => 159.99,
            'sub_total' => 133.32,
            'shipping_total' => 10.00,
            'original_shipping_total' => 10.00,
            'partial_payment_total' => 0.0,
            'marketing' => true,
            'comments' => 'Please leave at door',
            'tracking_url' => 'https://tracking.example.com/12345',
            'invoice_url' => 'https://shop.example.com/invoice/12345',
            'referrer_id' => 0,
            'earned_reward_points' => 15.99,
            'line_item_vat_calculation' => false,
            'status' => $this->statusPayload(),
            'billing_address' => $this->addressPayload(),
            'shipping_address' => $this->addressPayload(['name' => 'Jane Doe']),
            'customer' => $this->customerPayload(),
            'tax' => null,
            'shipping' => [$this->shippingPayload()],
            'discounts' => [],
            'fees' => [],
            'refunds' => [],
            'partial_payments' => [],
            'admin_comments' => [],
            'file_archives' => [],
            'package_weight' => null,
            'delivery_date' => null,
            'customer_source' => null,
            'transaction_id' => 'ch_abc123xyz',
            'products' => null,
            'custom_fields' => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function statusPayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 1,
            'name' => 'Paid',
            'type' => 'paid',
            'sort_order' => 0,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function addressPayload(array $overrides = []): array
    {
        return \array_merge([
            'name' => 'John Doe',
            'email_address' => 'john@example.com',
            'telephone' => '01onal234567890',
            'company_name' => 'Acme Corp',
            'address_line_1' => '123 Test Street',
            'address_line_2' => 'Suite 100',
            'address_line_3' => null,
            'city' => 'London',
            'province' => 'Greater London',
            'state' => null,
            'postcode' => 'SW1A 1AA',
            'country' => 'United Kingdom',
            'country_id' => 1,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function customerPayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 999,
            'type' => 1,
            'date_of_birth' => '1990-05-15',
            'device_info' => ['ip_address' => '192.168.1.1'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function shippingPayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 1,
            'name' => 'Standard Delivery',
            'value' => 10.00,
            'vat_rate' => 20.0,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function discountPayload(array $overrides = []): array
    {
        return \array_merge([
            'name' => 'SAVE10',
            'value' => 10.00,
            'type' => 'percentage',
            'code' => 'SAVE10',
            'voucher_id' => 123,
            'offer_id' => null,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return \array_merge([
            'id' => 1001,
            'title' => 'Test Product',
            'sku' => 'TEST-SKU-001',
            'price' => 50.00,
            'price_vat' => 10.00,
            'total' => 100.00,
            'total_vat' => 20.00,
            'original_price' => 50.00,
            'cost_price' => 25.00,
            'quantity' => 2,
            'vat_rate' => 20.0,
            'comments' => '',
            'variation' => [],
            'custom_fields' => [],
        ], $overrides);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Basic Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_complete_order_payload(): void
    {
        $dto = Order::from($this->completePayload());

        $this->assertSame(12345, $dto->id);
        $this->assertSame(67890, $dto->reference);
        $this->assertSame('2024-01-15T10:30:00+00:00', $dto->created);
        $this->assertFalse($dto->archived);
        $this->assertFalse($dto->anonymized);
        $this->assertFalse($dto->preOrder);
        $this->assertSame('Opayo Hosted', $dto->paymentMethod);
        $this->assertSame(159.99, $dto->total);
        $this->assertSame(133.32, $dto->subTotal);
        $this->assertSame(10.00, $dto->shippingTotal);
    }

    #[Test]
    public function from_parses_nested_status(): void
    {
        $dto = Order::from($this->completePayload());

        $this->assertInstanceOf(OrderStatus::class, $dto->status);
        $this->assertSame(1, $dto->status->id);
        $this->assertSame('Paid', $dto->status->name);
        $this->assertSame('paid', $dto->status->type);
        $this->assertSame(0, $dto->status->sortOrder);
    }

    #[Test]
    public function from_parses_nested_addresses(): void
    {
        $dto = Order::from($this->completePayload());

        $this->assertInstanceOf(OrderAddress::class, $dto->billingAddress);
        $this->assertSame('John Doe', $dto->billingAddress->name);
        $this->assertSame('john@example.com', $dto->billingAddress->emailAddress);
        $this->assertSame('123 Test Street', $dto->billingAddress->addressLine1);

        $this->assertInstanceOf(OrderAddress::class, $dto->shippingAddress);
        $this->assertSame('Jane Doe', $dto->shippingAddress->name);
    }

    #[Test]
    public function from_parses_nested_customer(): void
    {
        $dto = Order::from($this->completePayload());

        $this->assertInstanceOf(OrderCustomer::class, $dto->customer);
        $this->assertSame(999, $dto->customer->id);
        $this->assertSame(1, $dto->customer->type);
        $this->assertSame('1990-05-15', $dto->customer->dateOfBirth);
    }

    #[Test]
    public function from_parses_shipping_array(): void
    {
        $dto = Order::from($this->completePayload());

        $this->assertCount(1, $dto->shipping);
        $this->assertInstanceOf(OrderShipping::class, $dto->shipping[0]);
        $this->assertSame('Standard Delivery', $dto->shipping[0]->name);
        $this->assertSame(10.00, $dto->shipping[0]->value);
        $this->assertSame(20.0, $dto->shipping[0]->vatRate);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Discounts Parsing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_discounts_array(): void
    {
        $payload = $this->completePayload([
            'discounts' => [
                $this->discountPayload(),
                $this->discountPayload(['name' => 'LOYALTY5', 'value' => 5.00]),
            ],
        ]);

        $dto = Order::from($payload);

        $this->assertCount(2, $dto->discounts);
        $this->assertInstanceOf(OrderDiscount::class, $dto->discounts[0]);
        $this->assertSame('SAVE10', $dto->discounts[0]->name);
        $this->assertSame(10.00, $dto->discounts[0]->value);
        $this->assertSame('percentage', $dto->discounts[0]->type);
        $this->assertSame('SAVE10', $dto->discounts[0]->code);
        $this->assertSame(123, $dto->discounts[0]->voucherId);
        $this->assertNull($dto->discounts[0]->offerId);
    }

    /*
    |--------------------------------------------------------------------------
    | from() - Products Parsing (Detail Mode)
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_parses_products_in_detail_mode(): void
    {
        $payload = $this->completePayload([
            'products' => [
                $this->productPayload(),
                $this->productPayload(['id' => 1002, 'sku' => 'TEST-SKU-002']),
            ],
        ]);

        $dto = Order::from($payload);

        $this->assertCount(2, $dto->products);
        $this->assertInstanceOf(OrderProduct::class, $dto->products[0]);
        $this->assertSame(1001, $dto->products[0]->id);
        $this->assertSame('Test Product', $dto->products[0]->title);
        $this->assertSame('TEST-SKU-001', $dto->products[0]->sku);
        $this->assertSame(50.00, $dto->products[0]->price);
        $this->assertSame(2, $dto->products[0]->quantity);
    }

    #[Test]
    public function from_handles_null_products_in_standard_mode(): void
    {
        $dto = Order::from($this->completePayload(['products' => null]));

        $this->assertNull($dto->products);
    }

    /*
    |--------------------------------------------------------------------------
    | getFirstShipping() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_first_shipping_returns_first_shipping_option(): void
    {
        $payload = $this->completePayload([
            'shipping' => [
                $this->shippingPayload(['name' => 'Express']),
                $this->shippingPayload(['name' => 'Standard']),
            ],
        ]);

        $dto = Order::from($payload);

        $this->assertSame('Express', $dto->getFirstShipping()?->name);
    }

    #[Test]
    public function get_first_shipping_returns_null_when_shipping_is_empty(): void
    {
        $payload = $this->completePayload(['shipping' => []]);

        $dto = Order::from($payload);

        $this->assertNull($dto->getFirstShipping());
    }

    /*
    |--------------------------------------------------------------------------
    | toDomain() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function to_domain_returns_domain_order(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainOrder::class, $domain);
    }

    #[Test]
    public function to_domain_maps_scalar_fields(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertSame(67890, $domain->reference);
        $this->assertSame(159.99, $domain->total);
        $this->assertSame(133.32, $domain->subTotal);
        $this->assertSame(10.00, $domain->shippingTotal);
        $this->assertSame('Please leave at door', $domain->comments);
        $this->assertTrue($domain->marketing);
    }

    #[Test]
    public function to_domain_converts_payment_method(): void
    {
        $dto = Order::from($this->completePayload(['payment_method' => 'Opayo Hosted']));

        $domain = $dto->toDomain();

        $this->assertSame(PaymentMethod::Card, $domain->paymentMethod);
    }

    #[Test]
    public function to_domain_converts_status(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainOrderStatus::class, $domain->status);
        $this->assertSame(OrderStatusType::Paid, $domain->status->name);
        $this->assertSame('paid', $domain->status->type);
    }

    #[Test]
    public function to_domain_converts_customer(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainOrderCustomer::class, $domain->customer);
        $this->assertSame(999, $domain->customer->id);
        $this->assertSame(1, $domain->customer->type);
    }

    #[Test]
    public function to_domain_converts_shipping(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainOrderShipping::class, $domain->shipping);
        $this->assertSame('Standard Delivery', $domain->shipping->name);
        $this->assertSame(10.00, $domain->shipping->value);
    }

    #[Test]
    public function to_domain_handles_empty_shipping(): void
    {
        $dto = Order::from($this->completePayload(['shipping' => []]));

        $domain = $dto->toDomain();

        $this->assertNull($domain->shipping);
    }

    #[Test]
    public function to_domain_converts_addresses(): void
    {
        $dto = Order::from($this->completePayload());

        $domain = $dto->toDomain();

        $this->assertInstanceOf(DomainOrderAddress::class, $domain->billingAddress);
        $this->assertSame('John Doe', $domain->billingAddress->name);
        $this->assertSame('123 Test Street', $domain->billingAddress->addressLine1);

        $this->assertInstanceOf(DomainOrderAddress::class, $domain->shippingAddress);
        $this->assertSame('Jane Doe', $domain->shippingAddress->name);
    }

    #[Test]
    public function to_domain_converts_discounts(): void
    {
        $payload = $this->completePayload([
            'discounts' => [$this->discountPayload()],
        ]);
        $dto = Order::from($payload);

        $domain = $dto->toDomain();

        $this->assertCount(1, $domain->discounts);
        $this->assertInstanceOf(DomainOrderDiscount::class, $domain->discounts[0]);
        $this->assertSame('SAVE10', $domain->discounts[0]->name);
        $this->assertSame(10.00, $domain->discounts[0]->value);
    }

    #[Test]
    public function to_domain_converts_products_in_detail_mode(): void
    {
        $payload = $this->completePayload([
            'products' => [$this->productPayload()],
        ]);
        $dto = Order::from($payload);

        $domain = $dto->toDomain();

        $this->assertCount(1, $domain->products);
        $this->assertInstanceOf(DomainOrderProduct::class, $domain->products[0]);
        $this->assertSame('TEST-SKU-001', $domain->products[0]->sku);
    }

    #[Test]
    public function to_domain_keeps_null_products_in_standard_mode(): void
    {
        $dto = Order::from($this->completePayload(['products' => null]));

        $domain = $dto->toDomain();

        $this->assertNull($domain->products);
    }

    #[Test]
    public function to_domain_preserves_custom_fields(): void
    {
        $customFields = ['field1' => 'value1', 'field2' => 'value2'];
        $dto = Order::from($this->completePayload(['custom_fields' => $customFields]));

        $domain = $dto->toDomain();

        $this->assertSame($customFields, $domain->customFields);
    }
}
