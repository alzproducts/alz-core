<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Order value object.
 *
 * ShopWired-specific view of an order (Catalog bounded context).
 * Contains business-essential fields only - infrastructure details
 * like tax, fees, refunds stay in Infrastructure DTOs.
 *
 * @property array<int, OrderProduct>|null $products Null for summary, populated for detail
 * @property array<int, OrderDiscount> $discounts
 * @property array<string, mixed> $customFields
 */
final readonly class Order
{
    /**
     * @param array<int, OrderProduct>|null $products Null for summary requests, populated for detail
     * @param array<int, OrderDiscount> $discounts
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public int $reference,
        public float $total,
        public float $subTotal,
        public float $shippingTotal,
        public PaymentMethod $paymentMethod,
        public ?string $comments,
        public bool $marketing,
        public OrderStatus $status,
        public ?OrderCustomer $customer,
        public ?OrderShipping $shipping,
        public ?OrderAddress $billingAddress,
        public ?OrderAddress $shippingAddress,
        public array $discounts = [],
        public ?array $products = null,
        public array $customFields = [],
    ) {
        Assert::greaterThan($reference, 0, 'Order reference must be positive');
    }

    /**
     * Check if this is a detailed order (has products loaded).
     */
    public function hasProducts(): bool
    {
        return $this->products !== null;
    }

    /**
     * Check if order has any discounts applied.
     */
    public function hasDiscounts(): bool
    {
        return $this->discounts !== [];
    }

    /**
     * Get total discount value.
     */
    public function totalDiscountValue(): float
    {
        return \array_reduce(
            $this->discounts,
            static fn(float $carry, OrderDiscount $discount): float => $carry + $discount->value,
            0.0,
        );
    }
}
