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
 * Two-mode approach:
 * - Standard: products=null, customFields=null (not requested)
 * - Detail: products=[], customFields=[] (requested, possibly empty)
 *
 * @property array<int, OrderProduct>|null $products Null=not requested, []=empty
 * @property array<int, OrderDiscount> $discounts
 * @property array<string, mixed>|null $customFields Null=not requested, []=empty
 */
final readonly class Order
{
    /**
     * @param array<int, OrderProduct>|null $products Null=Standard mode, array=Detail mode
     * @param array<int, OrderDiscount> $discounts
     * @param array<string, mixed>|null $customFields Null=Standard mode, array=Detail mode
     */
    public function __construct(
        public int $reference,
        public float $total,
        public float $subTotal,
        public float $shippingTotal,
        public PaymentMethod $paymentMethod,
        public string $comments,
        public bool $marketing,
        public OrderStatus $status,
        public OrderCustomer $customer,
        public ?OrderShipping $shipping,
        public OrderAddress $billingAddress,
        public OrderAddress $shippingAddress,
        public array $discounts = [],
        public ?array $products = null,
        public ?array $customFields = null,
    ) {
        Assert::greaterThan($reference, 0, 'Order reference must be positive');
        Assert::greaterThanEq($total, 0, 'Order total cannot be negative');
        Assert::greaterThanEq($subTotal, 0, 'Order sub-total cannot be negative');
        Assert::greaterThanEq($shippingTotal, 0, 'Order shipping total cannot be negative');
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
