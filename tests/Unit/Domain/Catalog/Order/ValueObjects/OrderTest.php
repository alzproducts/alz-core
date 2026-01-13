<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAddress;
use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Catalog\Order\ValueObjects\PaymentMethod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Order Value Object Unit Tests.
 *
 * Tests business logic methods only - PHPStan handles type/structure validation.
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
            'orderPlacedAt' => new DateTimeImmutable('2024-01-15T10:30:00+00:00'),
            'total' => 110.50,
            'subTotal' => 100.00,
            'shippingTotal' => 10.50,
            'originalShippingTotal' => 10.50,
            'paymentMethod' => PaymentMethod::Card,
            'comments' => '',
            'marketing' => true,
            'hasVatRelief' => false,
            'isArchived' => false,
            'isAnonymized' => false,
            'lineItemVatCalculation' => false,
            'status' => new OrderStatus(1, OrderStatusType::Completed, 'shipped', 0),
            'customer' => new OrderCustomer(99, 1, null, []),
            'shipping' => new OrderShipping(id: 1, name: 'Standard', value: 10.50, vatRate: 20.0),
            'billingAddress' => $this->createOrderAddress(),
            'shippingAddress' => $this->createOrderAddress(),
            'preOrderStatus' => PreOrderStatus::None,
            'taxValue' => null,
            'trackingUrl' => null,
            'invoiceUrl' => null,
            'transactionId' => null,
            'deliveryDate' => null,
            'discounts' => [],
            'refunds' => [],
            'adminComments' => [],
            'products' => null,
            'customFields' => null,
        ];

        return new Order(...\array_merge($defaults, $overrides));
    }

    private function createOrderAddress(): OrderAddress
    {
        return new OrderAddress(
            name: 'John Doe',
            emailAddress: 'john@example.com',
            telephone: '01234567890',
            companyName: '',
            addressLine1: '123 Test St',
            addressLine2: '',
            addressLine3: null,
            city: 'London',
            province: '',
            state: null,
            postcode: 'SW1A 1AA',
            country: 'United Kingdom',
            countryId: 1,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | hasProducts() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_products_returns_false_when_products_is_null(): void
    {
        $order = $this->createOrder(['products' => null]);

        $this->assertFalse($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_is_empty_array(): void
    {
        $order = $this->createOrder(['products' => []]);

        $this->assertTrue($order->hasProducts());
    }

    #[Test]
    public function has_products_returns_true_when_products_array_has_items(): void
    {
        $products = [
            new OrderProduct(
                id: 1,
                orderExternalId: 98765,
                title: 'Test',
                sku: 'SKU-1',
                price: 10.0,
                priceVat: 2.0,
                total: 10.0,
                totalVat: 2.0,
                originalPrice: 10.0,
                costPrice: 5.0,
                quantity: 1,
                vatRate: 20.0,
                comments: '',
                isPreorder: false,
            ),
        ];
        $order = $this->createOrder(['products' => $products]);

        $this->assertTrue($order->hasProducts());
    }

    /*
    |--------------------------------------------------------------------------
    | hasDiscounts() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_discounts_returns_false_when_discounts_is_empty(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertFalse($order->hasDiscounts());
    }

    #[Test]
    public function has_discounts_returns_true_when_discounts_exist(): void
    {
        $discounts = [new OrderDiscount('VOUCHER', 10.0, null, null, null, null)];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertTrue($order->hasDiscounts());
    }

    /*
    |--------------------------------------------------------------------------
    | totalDiscountValue() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_discount_value_returns_zero_when_no_discounts(): void
    {
        $order = $this->createOrder(['discounts' => []]);

        $this->assertSame(0.0, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_returns_single_discount_value(): void
    {
        $discounts = [new OrderDiscount('15OFF', 15.75, null, null, null, null)];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(15.75, $order->totalDiscountValue());
    }

    #[Test]
    public function total_discount_value_sums_multiple_discounts(): void
    {
        $discounts = [
            new OrderDiscount('VOUCHER', 10.00, null, null, null, null),
            new OrderDiscount('SALE', 5.50, null, null, null, null),
            new OrderDiscount('LOYALTY', 2.25, null, null, null, null),
        ];
        $order = $this->createOrder(['discounts' => $discounts]);

        $this->assertSame(17.75, $order->totalDiscountValue());
    }

    /*
    |--------------------------------------------------------------------------
    | hasRefunds() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_refunds_returns_false_when_refunds_is_empty(): void
    {
        $order = $this->createOrder(['refunds' => []]);

        $this->assertFalse($order->hasRefunds());
    }

    #[Test]
    public function has_refunds_returns_true_when_refunds_exist(): void
    {
        $refunds = [
            new OrderRefund(1, 'Customer return', 15.00, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertTrue($order->hasRefunds());
    }

    /*
    |--------------------------------------------------------------------------
    | totalRefundValue() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function total_refund_value_returns_zero_when_no_refunds(): void
    {
        $order = $this->createOrder(['refunds' => []]);

        $this->assertSame(0.0, $order->totalRefundValue());
    }

    #[Test]
    public function total_refund_value_returns_single_refund_value(): void
    {
        $refunds = [
            new OrderRefund(1, 'Damaged item', 25.50, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertSame(25.50, $order->totalRefundValue());
    }

    #[Test]
    public function total_refund_value_sums_multiple_refunds(): void
    {
        $refunds = [
            new OrderRefund(1, 'Item 1 return', 10.00, new DateTimeImmutable()),
            new OrderRefund(2, 'Item 2 return', 5.50, new DateTimeImmutable()),
            new OrderRefund(3, 'Shipping refund', 2.25, new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['refunds' => $refunds]);

        $this->assertSame(17.75, $order->totalRefundValue());
    }

    /*
    |--------------------------------------------------------------------------
    | hasAdminComments() Tests - Business Logic
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function has_admin_comments_returns_false_when_comments_is_empty(): void
    {
        $order = $this->createOrder(['adminComments' => []]);

        $this->assertFalse($order->hasAdminComments());
    }

    #[Test]
    public function has_admin_comments_returns_true_when_comments_exist(): void
    {
        $comments = [
            new OrderAdminComment(1, 'Customer called about delivery', new DateTimeImmutable()),
        ];
        $order = $this->createOrder(['adminComments' => $comments]);

        $this->assertTrue($order->hasAdminComments());
    }
}
