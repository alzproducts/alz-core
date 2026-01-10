<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order Value Object Unit Tests.
 *
 * Tests the Order domain value object including assertions and business logic methods.
 */
#[CoversClass(Order::class)]
final class OrderTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Test Fixtures
    |--------------------------------------------------------------------------
    */

    /**
     * Create a valid order with optional overrides.
     *
     * @param array<string, mixed> $overrides
     */
    private function createOrder(array $overrides = []): Order
    {
        $defaults = [
            'id' => 98765,
            'reference' => 12345,
            'total' => 110.50,
            'subTotal' => 100.00,
            'shippingTotal' => 10.50,
            'paymentMethod' => PaymentMethod::Card,
            'comments' => 'Test order comments.',
            'marketing' => true,
            'status' => $this->createOrderStatus(),
            'customer' => $this->createOrderCustomer(),
            'shipping' => $this->createOrderShipping(),
            'billingAddress' => $this->createOrderAddress(),
            'shippingAddress' => $this->createOrderAddress(),
            'discounts' => [],
            'products' => null,
            'customFields' => null,
        ];

        $data = \array_merge($defaults, $overrides);

        return new Order(...$data);
    }

    private function createOrderAddress(): OrderAddress
    {
        return new OrderAddress(
            name: 'John Doe',
            emailAddress: 'john.doe@example.com',
            telephone: '01234567890',
            companyName: 'Acme Corp',
            addressLine1: '123 Test Street',
            addressLine2: 'Testville',
            addressLine3: null,
            city: 'Testington',
            province: 'Testshire',
            state: null,
            postcode: 'TS1 1AA',
            country: 'United Kingdom',
        );
    }

    private function createOrderCustomer(): OrderCustomer
    {
        return new OrderCustomer(
            id: 99,
            type: 1,
            dateOfBirth: '1990-01-01',
            deviceInfo: ['ipAddress' => '127.0.0.1'],
        );
    }

    private function createOrderShipping(): OrderShipping
    {
        return new OrderShipping(
            name: 'Standard Delivery',
            value: 10.50,
            vatRate: 20.0,
        );
    }

    private function createOrderStatus(): OrderStatus
    {
        return new OrderStatus(
            name: OrderStatusType::Completed,
            type: 'shipped',
        );
    }

    private function createOrderProduct(): OrderProduct
    {
        return new OrderProduct(
            id: 1,
            title: 'Test Product',
            sku: 'TEST-001',
            price: 50.0,
            priceVat: 10.0,
            total: 100.0,
            totalVat: 20.0,
            originalPrice: 50.0,
            costPrice: 25.0,
            quantity: 2,
            vatRate: 20.0,
            comments: '',
            variation: [],
            customFields: [],
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Construction & Assertion Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_creates_an_order_with_valid_data(): void
    {
        $order = $this->createOrder();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(98765, $order->id);
        $this->assertSame(12345, $order->reference);
        $this->assertSame(110.50, $order->total);
    }

    #[Test]
    #[DataProvider('invalidIdProvider')]
    public function it_throws_if_id_is_not_positive(int $invalidId): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order ID must be positive');

        $this->createOrder(['id' => $invalidId]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidIdProvider(): array
    {
        return [
            'zero id' => [0],
            'negative id' => [-1],
            'large negative id' => [-99999],
        ];
    }

    #[Test]
    #[DataProvider('invalidReferenceProvider')]
    public function it_throws_if_reference_is_not_positive(int $invalidReference): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order reference must be positive');

        $this->createOrder(['reference' => $invalidReference]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidReferenceProvider(): array
    {
        return [
            'zero reference' => [0],
            'negative reference' => [-1],
            'large negative reference' => [-99999],
        ];
    }

    #[Test]
    public function it_accepts_a_positive_boundary_reference(): void
    {
        $order = $this->createOrder(['reference' => 1]);

        $this->assertSame(1, $order->reference);
    }

    /*
    |--------------------------------------------------------------------------
    | hasProducts() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_products_returns_false_when_products_are_null(): void
    {
        $order = $this->createOrder(['products' => null]);

        $this->assertFalse($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_is_an_empty_array(): void
    {
        $order = $this->createOrder(['products' => []]);

        $this->assertTrue($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_array_is_populated(): void
    {
        $order = $this->createOrder(['products' => [$this->createOrderProduct()]]);

        $this->assertTrue($order->hasProducts());
    }

    /*
    |--------------------------------------------------------------------------
    | hasDiscounts() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_discounts_returns_false_for_empty_discounts_array(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertFalse($order->hasDiscounts());
    }

    #[Test]
    public function has_discounts_returns_true_when_discounts_array_is_populated(): void
    {
        $discount = new OrderDiscount('SAVE10', 10.0, 'percentage', 'SAVE10', 1, 1);
        $order = $this->createOrder(['discounts' => [$discount]]);

        $this->assertTrue($order->hasDiscounts());
    }

    /*
    |--------------------------------------------------------------------------
    | totalDiscountValue() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_discount_value_returns_zero_when_no_discounts_are_applied(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertSame(0.0, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_returns_value_of_a_single_discount(): void
    {
        $discounts = [new OrderDiscount('15OFF', 15.75, null, null, null, null)];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(15.75, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_returns_sum_of_multiple_discounts(): void
    {
        $discounts = [
            new OrderDiscount('VOUCHER', 10.00, null, 'V1', null, null),
            new OrderDiscount('SALE', 5.50, null, null, null, null),
            new OrderDiscount('LOYALTY', 2.25, null, null, null, null),
        ];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(17.75, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_handles_discounts_with_zero_value(): void
    {
        $discounts = [
            new OrderDiscount('VOUCHER', 10.00, null, 'V1', null, null),
            new OrderDiscount('FREE_GIFT', 0.00, null, null, null, null),
        ];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(10.00, $order->totalDiscountValue());
    }
}
