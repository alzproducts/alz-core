<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Infrastructure\Shopwired\Enums\PaymentMethodRaw;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order.
 *
 * Two-mode approach:
 * - Standard: All fields + embeds, NO products, NO customFields
 * - Detail: Standard + products + customFields
 *
 * Gotchas:
 * - `shipping` is returned as array from API; use getFirstShipping()
 * - Empty arrays (adminComments, fileArchives) return [] not null
 * - Detail-only fields return null in Standard mode (products, customFields)
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class Order extends Data
{
    /**
     * @param list<OrderShipping> $shipping API returns as array; use getFirstShipping()
     * @param list<OrderDiscount> $discounts
     * @param list<OrderFee> $fees
     * @param list<OrderRefund> $refunds
     * @param list<OrderPartialPayment> $partialPayments
     * @param list<OrderAdminComment> $adminComments Returns [] when empty
     * @param list<OrderFileArchive> $fileArchives Returns [] when empty
     * @param list<OrderProduct>|null $products Detail-only (null in Standard mode)
     * @param array<string, mixed>|null $customFields Detail-only (null in Standard mode)
     */
    public function __construct(
        // Identifiers (always present)
        public readonly int $id,
        public readonly int $reference,
        public readonly string $created,

        // Status flags (always present)
        public readonly bool $archived,
        public readonly bool $anonymized,
        public readonly bool $preOrder,

        // Payment (always present)
        public readonly string $paymentMethod,

        // Totals (always present)
        public readonly float $total,
        public readonly float $subTotal,
        public readonly float $shippingTotal,
        public readonly float $originalShippingTotal,
        public readonly float $partialPaymentTotal,

        // Weight (always present)
        public readonly string $totalWeight,
        public readonly string $weightUnit,
        public readonly string $packageWeight,

        // Customer preferences (always present)
        public readonly bool $marketing,
        public readonly string $comments,

        // URLs (always present, empty string if not set)
        public readonly string $trackingUrl,
        public readonly string $invoiceUrl,
        public readonly string $transactionId,

        // Source tracking (always present)
        public readonly int $referrerId,

        // Rewards (always present)
        public readonly float $earnedRewardPoints,

        // Calculation flags (always present)
        public readonly bool $lineItemVatCalculation,

        // Nested objects (always present)
        public readonly OrderStatus $status,
        public readonly OrderAddress $billingAddress,
        public readonly OrderAddress $shippingAddress,
        public readonly OrderTax $tax,
        public readonly OrderCustomer $customer,

        // Nested arrays (always present, default to empty)
        #[DataCollectionOf(OrderShipping::class)]
        public readonly array $shipping = [],
        #[DataCollectionOf(OrderDiscount::class)]
        public readonly array $discounts = [],
        #[DataCollectionOf(OrderFee::class)]
        public readonly array $fees = [],
        #[DataCollectionOf(OrderRefund::class)]
        public readonly array $refunds = [],
        #[DataCollectionOf(OrderPartialPayment::class)]
        public readonly array $partialPayments = [],
        #[DataCollectionOf(OrderAdminComment::class)]
        public readonly array $adminComments = [],
        #[DataCollectionOf(OrderFileArchive::class)]
        public readonly array $fileArchives = [],

        // Genuinely nullable (business data may not exist)
        public readonly ?string $deliveryDate = null,
        public readonly ?string $customerSource = null,

        // Detail-only fields (null in Standard mode)
        #[DataCollectionOf(OrderProduct::class)]
        public readonly ?array $products = null,
        public readonly ?array $customFields = null,
    ) {}

    /**
     * Get the first shipping option (API returns array).
     */
    public function getFirstShipping(): ?OrderShipping
    {
        return $this->shipping[0] ?? null;
    }

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\Order
    {
        return new \App\Domain\Catalog\Order\ValueObjects\Order(
            reference: $this->reference,
            total: $this->total,
            subTotal: $this->subTotal,
            shippingTotal: $this->shippingTotal,
            paymentMethod: PaymentMethodRaw::fromApiValue($this->paymentMethod)->toDomain(),
            comments: $this->comments,
            marketing: $this->marketing,
            status: $this->status->toDomain(),
            customer: $this->customer->toDomain(),
            shipping: $this->getFirstShipping()?->toDomain(),
            billingAddress: $this->billingAddress->toDomain(),
            shippingAddress: $this->shippingAddress->toDomain(),
            discounts: \array_map(
                static fn(OrderDiscount $d): \App\Domain\Catalog\Order\ValueObjects\OrderDiscount => $d->toDomain(),
                $this->discounts,
            ),
            products: ($this->products !== null)
                ? \array_map(
                    static fn(OrderProduct $p): \App\Domain\Catalog\Order\ValueObjects\OrderProduct => $p->toDomain(),
                    $this->products,
                )
                : null,
            customFields: $this->customFields,
        );
    }
}
