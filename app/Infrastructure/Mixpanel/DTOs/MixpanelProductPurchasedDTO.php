<?php

declare(strict_types=1);

namespace App\Infrastructure\Mixpanel\DTOs;

use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use Webmozart\Assert\Assert;

/**
 * DTO for Mixpanel "Product Purchased" event.
 *
 * Transforms a single OrderProduct into Mixpanel event format for backend order sync.
 * Each product in an order produces one Product Purchased event.
 *
 * Deduplication: Uses order_id_hashed for pre-export dedup check (frontend compatibility)
 * and deterministic $insert_id combining order hash + SKU hash for Mixpanel-native idempotency.
 */
final readonly class MixpanelProductPurchasedDTO
{
    private const string EVENT_NAME = 'Product Purchased';
    private const string INSERT_ID_PREFIX = 'PP';
    private const string SOURCE = 'backend-sync';
    private const string CURRENCY = 'GBP';

    /**
     * @param string $insertId Deduplication ID (max 36 chars)
     * @param string $userId ShopWired customer ID (distinct_id)
     * @param int $timestamp Order placed timestamp (Unix epoch)
     * @param string $orderIdHashed SHA-256 hash matching frontend algorithm
     * @param string $sku Product SKU
     * @param string $name Product name/title
     * @param float $price Unit price
     * @param int $quantity Quantity purchased
     * @param float $total Line total (price × quantity)
     * @param string $paymentMethod Payment method identifier
     * @param string $shippingCountry Shipping address country
     * @param bool $isBusinessUser Whether customer is a trade account
     */
    public function __construct(
        public string $insertId,
        public string $userId,
        public int $timestamp,
        public string $orderIdHashed,
        public string $sku,
        public string $name,
        public float $price,
        public int $quantity,
        public float $total,
        public string $paymentMethod,
        public string $shippingCountry,
        public bool $isBusinessUser,
    ) {
        Assert::notEmpty($insertId, 'Insert ID cannot be empty');
        Assert::lessThanEq(\mb_strlen($insertId), 36, 'Insert ID must be ≤36 characters');
        Assert::notEmpty($userId, 'User ID cannot be empty');
        Assert::greaterThan($timestamp, 0, 'Timestamp must be positive Unix time');
        Assert::notEmpty($orderIdHashed, 'Order ID hash cannot be empty');
        Assert::length($orderIdHashed, 64, 'Order ID hash must be 64 characters (SHA-256)');
        Assert::notEmpty($sku, 'Product SKU cannot be empty');
    }

    /**
     * Transform Domain Order + OrderProduct into Mixpanel product event DTO.
     *
     * @param Order $order Domain order for context (timestamp, customer, payment)
     * @param OrderProduct $product Single product from the order
     * @param string $analyticsSalt Salt for order_id_hashed (must match frontend)
     * @param bool $isBusinessUser Whether customer is a trade account
     */
    public static function fromOrderProduct(
        Order $order,
        OrderProduct $product,
        string $analyticsSalt,
        bool $isBusinessUser,
    ): self {
        Assert::notEmpty($analyticsSalt, 'Analytics salt cannot be empty');

        $orderIdHashed = MixpanelCheckoutCompletedDTO::hashOrderId($order->reference, $analyticsSalt);

        return new self(
            insertId: self::generateInsertId($orderIdHashed, $product->sku),
            userId: (string) $order->customer->id,
            timestamp: $order->orderPlacedAt->getTimestamp(),
            orderIdHashed: $orderIdHashed,
            sku: $product->sku,
            name: $product->title,
            price: $product->price,
            quantity: $product->quantity,
            total: $product->total,
            paymentMethod: $order->paymentMethod->value,
            shippingCountry: $order->shippingAddress->country,
            isBusinessUser: $isBusinessUser,
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
                'sku' => $this->sku,
                'name' => $this->name,
                'price' => $this->price,
                'quantity' => $this->quantity,
                'total' => $this->total,
                'currency' => self::CURRENCY,
                'payment_method' => $this->paymentMethod,
                'source' => self::SOURCE,
                'is_quote' => false,
                'user_is_business_user' => $this->isBusinessUser,
                'order_shipping_country' => $this->shippingCountry,
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
     * Generate deduplication ID for Mixpanel.
     *
     * Format: "PP-{orderHash16}-{skuHash8}" (28 chars total, under 36 char limit)
     *
     * Using both order hash and SKU hash ensures uniqueness per product within an order,
     * while remaining deterministic for idempotent imports.
     */
    private static function generateInsertId(string $orderIdHashed, string $sku): string
    {
        // Take first 16 chars of order hash + first 8 chars of SKU hash
        $orderPart = \mb_substr($orderIdHashed, 0, 16);
        $skuHash = \hash('sha256', $sku);
        $skuPart = \mb_substr($skuHash, 0, 8);

        return self::INSERT_ID_PREFIX . '-' . $orderPart . '-' . $skuPart;
    }
}
