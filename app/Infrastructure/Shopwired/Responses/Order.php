<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order.
 *
 * Infrastructure DTO for parsing order data from API responses.
 * Supports both summary (list) and detail (getById) responses.
 *
 * Summary responses have nullable detail fields (products, adminComments, fileArchives).
 * Detail responses have all fields populated.
 *
 * Gotchas:
 * - `shipping` is returned as array from API; use shipping[0] when parsing
 * - `preOrder` is a required boolean (not nullable)
 * - `customFields` is an object/assoc array, not a flat array
 *
 * Domain conversion will be added after smoke testing validates parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class Order extends Data
{
    /**
     * @param list<OrderShipping>|null $shipping API returns as array; typically shipping[0]
     * @param list<OrderDiscount> $discounts
     * @param list<OrderFee> $fees
     * @param list<OrderRefund> $refunds
     * @param list<OrderPartialPayment> $partialPayments
     * @param list<OrderProduct>|null $products Null for summary, populated for detail
     * @param list<OrderAdminComment>|null $adminComments Null for summary, populated for detail
     * @param list<OrderFileArchive>|null $fileArchives Null for summary, populated for detail
     * @param array<string, mixed> $customFields Order-level custom fields (key-value pairs)
     */
    public function __construct(
        // Identifiers
        public readonly ?int $id = null,
        public readonly ?int $reference = null,
        public readonly ?string $created = null,

        // Status flags
        public readonly ?bool $archived = null,
        public readonly ?bool $anonymized = null,
        public readonly bool $preOrder = false,

        // URLs
        public readonly ?string $trackingUrl = null,
        public readonly ?string $invoiceUrl = null,

        // Payment
        public readonly ?string $paymentMethod = null,
        public readonly ?string $transactionId = null,

        // Totals
        public readonly ?float $total = null,
        public readonly ?float $subTotal = null,
        public readonly ?float $shippingTotal = null,
        public readonly ?float $originalShippingTotal = null,
        public readonly ?float $partialPaymentTotal = null,

        // Weight
        public readonly ?string $totalWeight = null,
        public readonly ?string $weightUnit = null,
        public readonly ?string $packageWeight = null,

        // Customer preferences
        public readonly ?bool $marketing = null,
        public readonly ?string $comments = null,

        // Dates
        public readonly ?string $deliveryDate = null,

        // Rewards
        public readonly ?float $earnedRewardPoints = null,

        // Calculation flags
        public readonly ?bool $lineItemVatCalculation = null,

        // Source tracking
        public readonly ?int $referrerId = null,
        public readonly ?string $customerSource = null,

        // Nested objects
        public readonly ?OrderStatus $status = null,
        public readonly ?OrderAddress $billingAddress = null,
        public readonly ?OrderAddress $shippingAddress = null,
        public readonly ?OrderTax $tax = null,
        public readonly ?OrderCustomer $customer = null,

        // Nested arrays (always present)
        #[DataCollectionOf(OrderShipping::class)]
        public readonly ?array $shipping = null,
        #[DataCollectionOf(OrderDiscount::class)]
        public readonly array $discounts = [],
        #[DataCollectionOf(OrderFee::class)]
        public readonly array $fees = [],
        #[DataCollectionOf(OrderRefund::class)]
        public readonly array $refunds = [],
        #[DataCollectionOf(OrderPartialPayment::class)]
        public readonly array $partialPayments = [],

        // Detail-only fields (null for summary requests)
        #[DataCollectionOf(OrderProduct::class)]
        public readonly ?array $products = null,
        #[DataCollectionOf(OrderAdminComment::class)]
        public readonly ?array $adminComments = null,
        #[DataCollectionOf(OrderFileArchive::class)]
        public readonly ?array $fileArchives = null,

        // Custom fields (order-level, key-value pairs)
        public readonly array $customFields = [],
    ) {}

    /**
     * Get the first shipping option (API returns array).
     */
    public function getFirstShipping(): ?OrderShipping
    {
        return $this->shipping[0] ?? null;
    }
}
