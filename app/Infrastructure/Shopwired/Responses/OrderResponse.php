<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Shopwired\Enums\PaymentMethodRaw;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use TypeError;

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
final class OrderResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<OrderShippingResponse> $shipping API returns as array; use getFirstShipping()
     * @param list<OrderDiscountResponse> $discounts
     * @param list<OrderFeeResponse> $fees
     * @param list<OrderRefundResponse> $refunds
     * @param list<OrderPartialPaymentResponse> $partialPayments
     * @param list<OrderAdminCommentResponse> $adminComments Returns [] when empty
     * @param list<OrderFileArchiveResponse> $fileArchives Returns [] when empty
     * @param list<OrderProductResponse>|null $products Detail-only (null in Standard mode)
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

        // Customer preferences (always present)
        public readonly bool $marketing,
        public readonly string $comments,

        // URLs (always present, empty string if not set)
        public readonly string $trackingUrl,
        public readonly string $invoiceUrl,

        // Source tracking (always present)
        public readonly int $referrerId,

        // Rewards (always present)
        public readonly float $earnedRewardPoints,

        // Calculation flags (always present)
        public readonly bool $lineItemVatCalculation,

        // Nested objects (always present)
        public readonly OrderStatusResponse $status,
        public readonly OrderAddressResponse $billingAddress,
        public readonly OrderAddressResponse $shippingAddress,
        public readonly OrderCustomerResponse $customer,

        // Tax (nullable - VAT-exempt orders may not have tax)
        public readonly ?OrderTaxResponse $tax = null,

        // Nested arrays (always present, default to empty)
        #[DataCollectionOf(OrderShippingResponse::class)]
        public readonly array $shipping = [],
        #[DataCollectionOf(OrderDiscountResponse::class)]
        public readonly array $discounts = [],
        #[DataCollectionOf(OrderFeeResponse::class)]
        public readonly array $fees = [],
        #[DataCollectionOf(OrderRefundResponse::class)]
        public readonly array $refunds = [],
        #[DataCollectionOf(OrderPartialPaymentResponse::class)]
        public readonly array $partialPayments = [],
        #[DataCollectionOf(OrderAdminCommentResponse::class)]
        public readonly array $adminComments = [],
        #[DataCollectionOf(OrderFileArchiveResponse::class)]
        public readonly array $fileArchives = [],

        // Genuinely nullable (business data may not exist)
        public readonly ?string $packageWeight = null,
        public readonly ?string $deliveryDate = null,
        public readonly ?string $customerSource = null,
        public readonly ?string $transactionId = null,

        // Detail-only fields (null in Standard mode)
        #[DataCollectionOf(OrderProductResponse::class)]
        public readonly ?array $products = null,
        public readonly ?array $customFields = null,
    ) {}

    /**
     * Get the first shipping option (API returns array).
     */
    public function getFirstShipping(): ?OrderShippingResponse
    {
        return $this->shipping[0] ?? null;
    }

    /**
     * @throws InvalidApiResponseException When nested status has unknown enum value or created timestamp invalid
     * @throws TypeError When nested status type mismatches (should not occur with proper Spatie Data parsing)
     */
    public function toDomain(): Order
    {
        try {
            $orderPlacedAt = new DateTimeImmutable($this->created);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                'ShopWired',
                "Invalid order created timestamp: {$this->created}",
                $e,
            );
        }

        $products = $this->products !== null
            ? \array_map(
                fn(OrderProductResponse $p): OrderProduct => $p->toDomain($this->id),
                $this->products,
            )
            : null;

        return new Order(
            id: $this->id,
            reference: $this->reference,
            orderPlacedAt: $orderPlacedAt,
            total: $this->total,
            subTotal: $this->subTotal,
            shippingTotal: $this->shippingTotal,
            originalShippingTotal: $this->originalShippingTotal,
            paymentMethod: PaymentMethodRaw::fromApiValue($this->paymentMethod)->toDomain(),
            comments: $this->comments,
            marketing: $this->marketing,
            hasVatRelief: $this->hasVatRelief(),
            isArchived: $this->archived,
            isAnonymized: $this->anonymized,
            lineItemVatCalculation: $this->lineItemVatCalculation,
            status: $this->status->toDomain(),
            customer: $this->customer->toDomain(),
            shipping: $this->getFirstShipping()?->toDomain(),
            billingAddress: $this->billingAddress->toDomain(),
            shippingAddress: $this->shippingAddress->toDomain(),
            preOrderStatus: $this->derivePreOrderStatus($products),
            taxValue: $this->tax?->value,
            trackingUrl: $this->trackingUrl !== '' ? $this->trackingUrl : null,
            invoiceUrl: $this->invoiceUrl !== '' ? $this->invoiceUrl : null,
            transactionId: $this->transactionId,
            deliveryDate: $this->parseDeliveryDate(),
            discounts: \array_map(
                static fn(OrderDiscountResponse $d): OrderDiscount => $d->toDomain(),
                $this->discounts,
            ),
            refunds: \array_map(
                static fn(OrderRefundResponse $r): OrderRefund => $r->toDomain(),
                $this->refunds,
            ),
            adminComments: \array_map(
                static fn(OrderAdminCommentResponse $c): OrderAdminComment => $c->toDomain(),
                $this->adminComments,
            ),
            products: $products,
            customFields: $this->customFields,
        );
    }

    /**
     * Check if the order has VAT relief applied.
     *
     * Derived from order comments - customers indicate VAT relief eligibility
     * by including "vat relief" or "vat-relief" text in their order comments.
     */
    private function hasVatRelief(): bool
    {
        $comments = \mb_strtolower($this->comments);

        return \str_contains($comments, 'vat relief') || \str_contains($comments, 'vat-relief');
    }

    /**
     * Parse delivery date from API string format.
     */
    private function parseDeliveryDate(): ?DateTimeImmutable
    {
        if ($this->deliveryDate === null || $this->deliveryDate === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($this->deliveryDate);
        } catch (DateMalformedStringException) {
            // Invalid date format - return null rather than failing
            return null;
        }
    }

    /**
     * Derive pre-order status from product-level isPreorder flags.
     *
     * @param array<int, OrderProduct>|null $products Converted domain products
     */
    private function derivePreOrderStatus(?array $products): PreOrderStatus
    {
        if ($products === null || $products === []) {
            // No products loaded or empty - use API's order-level preOrder flag
            return $this->preOrder ? PreOrderStatus::Full : PreOrderStatus::None;
        }

        $preorderCount = 0;
        foreach ($products as $product) {
            if ($product->isPreorder) {
                $preorderCount++;
            }
        }

        if ($preorderCount === 0) {
            return PreOrderStatus::None;
        }

        return $preorderCount === \count($products)
            ? PreOrderStatus::Full
            : PreOrderStatus::Partial;
    }
}
