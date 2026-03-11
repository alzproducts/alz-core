<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Order\ValueObjects;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

/**
 * Order value object.
 *
 * ShopWired-specific view of an order (Catalog bounded context).
 * Contains business-essential fields only - infrastructure details
 * like fees, partial payments, weights stay in Infrastructure DTOs.
 *
 * Two-mode approach:
 * - Standard: products=null, customFields=null (not requested)
 * - Detail: products=[], customFields=[] (requested, possibly empty)
 *
 * @property array<int, OrderProduct>|null $products Null=not provided, []=empty
 * @property array<int, OrderDiscount>|null $discounts Null=not provided (webhook partial update), []=empty
 * @property array<int, OrderRefund>|null $refunds Null=not provided (webhook partial update), []=empty
 * @property array<int, OrderAdminComment>|null $adminComments Null=not provided (webhook partial update), []=empty
 * @property array<string, mixed>|null $customFields Null=not provided, []=empty
 */
final readonly class Order
{
    /** Comment delimiter used in legacy structured comments. */
    public const string COMMENT_DELIM_OLD = ':-';

    /** Comment delimiter used in current structured comments. */
    public const string COMMENT_DELIM_NEW = '*>';

    /**
     * @param int $id ShopWired's order ID (external identifier for API/persistence)
     * @param int $reference Customer-facing order reference number
     * @param DateTimeImmutable $orderPlacedAt When the order was placed by customer
     * @param float $total Order total including VAT (GROSS)
     * @param float $subTotalNet Subtotal excluding VAT and shipping (NET)
     * @param float $shippingTotalNet Shipping cost after discounts, excluding VAT (NET)
     * @param float $originalShippingTotalNet Original shipping cost before discounts, excluding VAT (NET)
     * @param bool $hasVatRelief Whether order has VAT relief applied (derived from comments)
     * @param bool $isArchived Whether order has been archived in ShopWired
     * @param bool $isAnonymized Whether customer data has been anonymized (GDPR)
     * @param bool $lineItemVatCalculation Whether VAT is calculated per line item
     * @param float|null $taxValue Total tax value (null for VAT-exempt orders)
     * @param string|null $trackingUrl Shipment tracking URL
     * @param string|null $invoiceUrl Invoice download URL
     * @param string|null $transactionId Payment transaction ID
     * @param DateTimeImmutable|null $deliveryDate Expected/actual delivery date
     * @param PreOrderStatus $preOrderStatus Derived from product-level isPreorder flags
     * @param array<int, OrderProduct>|null $products Null=not provided, array=provided
     * @param array<int, OrderDiscount>|null $discounts Null=not provided (skip sync), array=provided
     * @param array<int, OrderRefund>|null $refunds Null=not provided (skip sync), array=provided
     * @param array<int, OrderAdminComment>|null $adminComments Null=not provided (skip sync), array=provided
     * @param array<string, mixed>|null $customFields Null=not provided, array=provided
     * @param string|null $customerReferenceNumber Extracted from comments (null if not present/extractable)
     */
    public function __construct(
        public int $id,
        public int $reference,
        public DateTimeImmutable $orderPlacedAt,
        public float $total,
        public float $subTotalNet,
        public float $shippingTotalNet,
        public float $originalShippingTotalNet,
        public PaymentMethod $paymentMethod,
        public string $comments,
        public bool $marketing,
        public bool $hasVatRelief,
        public bool $isArchived,
        public bool $isAnonymized,
        public bool $lineItemVatCalculation,
        public OrderStatus $status,
        public OrderCustomer $customer,
        public ?OrderShipping $shipping,
        public OrderAddress $billingAddress,
        public OrderAddress $shippingAddress,
        public PreOrderStatus $preOrderStatus,
        public ?float $taxValue = null,
        public ?string $trackingUrl = null,
        public ?string $invoiceUrl = null,
        public ?string $transactionId = null,
        public ?DateTimeImmutable $deliveryDate = null,
        public ?array $discounts = null,
        public ?array $refunds = null,
        public ?array $adminComments = null,
        public ?array $products = null,
        public ?array $customFields = null,
        public ?string $customerReferenceNumber = null,
    ) {
        Assert::greaterThan($id, 0, 'Order ID must be positive');
        Assert::greaterThan($reference, 0, 'Order reference must be positive');
        Assert::greaterThanEq($total, 0, 'Order total cannot be negative');
        Assert::greaterThanEq($subTotalNet, 0, 'Order sub-total cannot be negative');
        Assert::greaterThanEq($shippingTotalNet, 0, 'Order shipping total cannot be negative');
        Assert::greaterThanEq($originalShippingTotalNet, 0, 'Order original shipping total cannot be negative');
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
        return $this->discounts !== null && $this->discounts !== [];
    }

    /**
     * Get total discount value.
     */
    public function totalDiscountValue(): float
    {
        if ($this->discounts === null) {
            return 0.0;
        }

        return \array_reduce(
            $this->discounts,
            static fn(float $carry, OrderDiscount $discount): float => $carry + $discount->value,
            0.0,
        );
    }

    /**
     * Check if order has any refunds applied.
     */
    public function hasRefunds(): bool
    {
        return $this->refunds !== null && $this->refunds !== [];
    }

    /**
     * Get total refund value.
     */
    public function totalRefundValue(): float
    {
        if ($this->refunds === null) {
            return 0.0;
        }

        return \array_reduce(
            $this->refunds,
            static fn(float $carry, OrderRefund $refund): float => $carry + $refund->value,
            0.0,
        );
    }

    /**
     * Check if order has any admin comments.
     */
    public function hasAdminComments(): bool
    {
        return $this->adminComments !== null && $this->adminComments !== [];
    }

    /**
     * Extract customer reference number from order comments.
     *
     * Looks for "Reference XYZ" pattern in comments and extracts the value.
     * Excludes structured comments that use delimiter markers.
     *
     * Rules:
     * - Case-insensitive search for "reference " keyword
     * - Must NOT contain delimiter markers (structured comments)
     * - Extracts text after "Reference " until end of line
     * - Truncates to 255 characters (database column limit)
     */
    public static function extractCustomerReferenceNumber(string $comments): ?string
    {
        if ($comments === '') {
            return null;
        }

        // Exclude structured comments (contain delimiters)
        if (\str_contains($comments, self::COMMENT_DELIM_OLD)
            || \str_contains($comments, self::COMMENT_DELIM_NEW)) {
            return null;
        }

        // Case-insensitive search for "reference "
        $pos = \mb_stripos($comments, 'reference ');
        if ($pos === false) {
            return null;
        }

        // Extract from after "Reference " until end of line (not end of string)
        $afterReference = \mb_substr($comments, $pos + 10);

        // Find first newline to stop extraction
        $newlinePos = \mb_strpos($afterReference, "\n");
        $reference = $newlinePos !== false
            ? \mb_substr($afterReference, 0, $newlinePos)
            : $afterReference;

        $reference = \mb_trim($reference);

        if ($reference === '') {
            return null;
        }

        // Truncate to 255 chars (database column limit)
        return \mb_substr($reference, 0, 255);
    }
}
