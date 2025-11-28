<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Product.
 *
 * Infrastructure DTO for parsing product data from order responses.
 * This is a snapshot of product data at time of purchase (not catalog product).
 *
 * Note: `customFields` is array of {name, value} objects, NOT a flat array.
 * Domain conversion will be added after smoke testing validates parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderProduct extends Data
{
    /**
     * @param list<array{name: string, value: string}>|null $extras
     * @param list<array{name: string, value: string}>|null $choices
     * @param list<array{name: string, value: string}>|null $variation
     * @param list<array{name: string, sku: string}>|null $bundleProducts
     * @param list<array{name: string, value: string}>|null $customFields
     */
    public function __construct(
        // Identifiers
        public readonly ?int $id = null,
        public readonly ?int $itemId = null,

        // Product info
        public readonly ?string $title = null,
        public readonly ?string $sku = null,
        public readonly ?string $gtin = null,
        public readonly ?string $mpn = null,

        // Pricing
        public readonly ?float $price = null,
        public readonly ?float $priceVat = null,
        public readonly ?float $total = null,
        public readonly ?float $totalVat = null,
        public readonly ?float $originalPrice = null,
        public readonly ?float $costPrice = null,

        // Quantity & Tax
        public readonly ?int $quantity = null,
        public readonly ?float $vatRate = null,

        // Physical
        public readonly ?float $weight = null,

        // Rewards
        public readonly ?float $rewardPointsEarned = null,

        // Notes
        public readonly ?string $comments = null,
        public readonly ?string $warehouseNotes = null,

        // Flags
        public readonly ?bool $giftVoucher = null,
        public readonly ?bool $preOrder = null,

        // Customs
        public readonly ?string $hsCode = null,

        // Nested arrays
        public readonly ?array $extras = null,
        public readonly ?array $choices = null,
        public readonly ?array $variation = null,
        public readonly ?array $bundleProducts = null,
        public readonly ?array $customFields = null,
    ) {}
}
