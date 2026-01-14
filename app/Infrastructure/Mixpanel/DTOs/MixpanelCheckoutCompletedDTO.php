<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\DTOs;

use App\Domain\Catalog\Order\Enums\PreOrderStatus;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderAnalyticsHash;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use Webmozart\Assert\Assert;

/**
 * DTO for Mixpanel "Checkout Completed" event.
 *
 * Transforms Domain Order into Mixpanel event format for backend order sync.
 * Each order produces exactly one Checkout Completed event.
 *
 * Deduplication: Uses order_id_hashed for pre-export dedup check (frontend compatibility)
 * and deterministic $insert_id for Mixpanel-native idempotency.
 */
final readonly class MixpanelCheckoutCompletedDTO
{
    private const string EVENT_NAME = 'Checkout Completed';
    private const string INSERT_ID_PREFIX = 'CC';
    private const string SOURCE = 'backend-sync';
    private const string CURRENCY = 'GBP';

    /**
     * Countries considered as UK for shipping analytics.
     * Includes Crown Dependencies to match frontend isUKAddress() behavior.
     *
     * @var list<string>
     */
    private const array UK_COUNTRIES = [
        'United Kingdom',
        'Jersey',
        'Isle of Man',
        'Guernsey',
        'Isle of Wight',
    ];

    /**
     * @param string $insertId Deduplication ID (max 36 chars)
     * @param string $userId ShopWired customer ID (distinct_id)
     * @param int $timestamp Order placed timestamp (Unix epoch)
     * @param string $orderIdHashed SHA-256 hash matching frontend algorithm
     * @param float $totalIncVat Final order total including VAT
     * @param float $subTotalExclShipping Subtotal before shipping/tax
     * @param float|null $vat Total VAT amount (null for VAT-exempt)
     * @param float $shippingTotal Shipping cost after discounts
     * @param float $totalExclVat Total excluding VAT
     * @param string $paymentMethod Payment method identifier
     * @param bool $isPreOrder Whether order status is Preorder
     * @param bool $isBusinessUser Whether customer is a trade account
     * @param string $shippingCountry Shipping address country
     * @param string $billingCountry Billing address country
     * @param bool $hasUkShipping Whether shipping to UK
     * @param int $itemCount Number of unique products
     * @param int $totalQuantity Sum of all product quantities
     * @param array<int, array{sku: string, name: string, price: float, quantity: int, total: float, position: int}> $cart Product details
     */
    public function __construct(
        public string $insertId,
        public string $userId,
        public int $timestamp,
        public string $orderIdHashed,
        public float $totalIncVat,
        public float $subTotalExclShipping,
        public ?float $vat,
        public float $shippingTotal,
        public float $totalExclVat,
        public string $paymentMethod,
        public bool $isPreOrder,
        public bool $isBusinessUser,
        public string $shippingCountry,
        public string $billingCountry,
        public bool $hasUkShipping,
        public int $itemCount,
        public int $totalQuantity,
        public array $cart,
    ) {
        Assert::notEmpty($insertId, 'Insert ID cannot be empty');
        Assert::lessThanEq(\mb_strlen($insertId), 36, 'Insert ID must be ≤36 characters');
        Assert::notEmpty($userId, 'User ID cannot be empty');
        Assert::greaterThan($timestamp, 0, 'Timestamp must be positive Unix time');
        Assert::notEmpty($orderIdHashed, 'Order ID hash cannot be empty');
        Assert::length($orderIdHashed, 64, 'Order ID hash must be 64 characters (SHA-256)');
    }

    /**
     * Transform Domain Order into Mixpanel checkout event DTO.
     *
     * @param Order $order Domain order with products populated
     * @param string $analyticsSalt Salt for order_id_hashed (must match frontend)
     * @param bool $isBusinessUser Whether customer is a trade account
     */
    public static function fromOrder(Order $order, string $analyticsSalt, bool $isBusinessUser): self
    {
        Assert::notNull($order->products, 'Order must have products loaded (detail mode)');
        Assert::notEmpty($order->products, 'Order must have at least one product');

        $orderIdHashed = OrderAnalyticsHash::fromReference($order->reference, $analyticsSalt)->value;

        return new self(
            insertId: self::generateInsertId($orderIdHashed),
            userId: (string) $order->customer->id,
            timestamp: $order->orderPlacedAt->getTimestamp(),
            orderIdHashed: $orderIdHashed,
            totalIncVat: $order->total,
            subTotalExclShipping: $order->subTotalNet,
            vat: $order->taxValue,
            shippingTotal: $order->shippingTotalNet,
            totalExclVat: $order->total - ($order->taxValue ?? 0.0),
            paymentMethod: $order->paymentMethod->value,
            isPreOrder: $order->preOrderStatus !== PreOrderStatus::None,
            isBusinessUser: $isBusinessUser,
            shippingCountry: $order->shippingAddress->country,
            billingCountry: $order->billingAddress->country,
            hasUkShipping: \in_array($order->shippingAddress->country, self::UK_COUNTRIES, true),
            itemCount: \count($order->products),
            totalQuantity: self::sumQuantities($order->products),
            cart: self::buildCart($order->products),
        );
    }

    /**
     * Transform to Mixpanel's expected JSON structure.
     *
     * @return array{event: string, properties: array<string, mixed>}
     */
    public function toMixpanelFormat(): array
    {
        return [
            'event' => self::EVENT_NAME,
            'properties' => [
                'time' => $this->timestamp,
                '$user_id' => $this->userId,
                '$insert_id' => $this->insertId,
                'order_id_hashed' => $this->orderIdHashed,
                'total_inc_vat' => $this->totalIncVat,
                'sub_total_excl_shipping' => $this->subTotalExclShipping,
                'vat' => $this->vat ?? 0.0,
                'shipping_total' => $this->shippingTotal,
                'total_excl_vat' => $this->totalExclVat,
                'currency' => self::CURRENCY,
                'payment_method' => $this->paymentMethod,
                'source' => self::SOURCE,
                'is_quote' => false,
                'is_pre_order' => $this->isPreOrder,
                'user_is_business_user' => $this->isBusinessUser,
                'order_shipping_country' => $this->shippingCountry,
                'order_billing_country' => $this->billingCountry,
                'has_uk_shipping_address' => $this->hasUkShipping,
                'item_count' => $this->itemCount,
                'total_quantity' => $this->totalQuantity,
                'cart' => $this->cart,
            ],
        ];
    }

    /**
     * Get the order_id_hashed for deduplication checks.
     */
    public function getOrderHash(): string
    {
        return $this->orderIdHashed;
    }

    /**
     * Generate deterministic hash matching frontend algorithm.
     *
     * Algorithm: SHA-256(reference + salt)
     * MUST match frontend: hash('sha256', order_reference + analytics_salt)
     */
    public static function hashOrderId(int $reference, string $analyticsSalt): string
    {
        return \hash('sha256', $reference . $analyticsSalt);
    }

    /**
     * Generate deduplication ID for Mixpanel.
     *
     * Format: "CC-{hash32}" (35 chars total, under 36 char limit)
     */
    private static function generateInsertId(string $orderIdHashed): string
    {
        // Take first 32 chars of 64-char SHA-256 hash
        return self::INSERT_ID_PREFIX . '-' . \mb_substr($orderIdHashed, 0, 32);
    }

    /**
     * Sum quantities across all products.
     *
     * @param array<int, OrderProduct> $products
     */
    private static function sumQuantities(array $products): int
    {
        return \array_reduce(
            $products,
            static fn(int $carry, OrderProduct $product): int => $carry + $product->quantity,
            0,
        );
    }

    /**
     * Build cart array matching frontend schema.
     *
     * @param array<int, OrderProduct> $products
     * @return list<array{sku: string, name: string, price: float, quantity: int, total: float, position: int}>
     */
    private static function buildCart(array $products): array
    {
        $cart = [];
        $position = 1;

        foreach ($products as $product) {
            $cart[] = [
                'sku' => $product->sku,
                'name' => $product->title,
                'price' => $product->price,
                'quantity' => $product->quantity,
                'total' => $product->total,
                'position' => $position++,
            ];
        }

        return $cart;
    }
}
