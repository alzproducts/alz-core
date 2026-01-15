<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderProduct;
use App\Domain\Catalog\Order\ValueObjects\ProductVariation;
use App\Domain\Exceptions\InvalidApiResponseException;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Product.
 *
 * Detail-only: Only returned when products field is requested.
 * Snapshot of product data at time of purchase (not catalog product).
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderProductResponse extends Data
{
    private const PREORDER_DATE_FORMAT = 'd/m/Y';

    /**
     * @param list<array{name: string, value: string}> $variation
     * @param list<array{name: string, value: string}> $customFields
     */
    public function __construct(
        // Identifiers
        public readonly int $id,

        // Product info
        public readonly string $title,
        public readonly string $sku,

        // Pricing (required)
        public readonly float $price,
        public readonly float $priceVat,
        public readonly float $total,
        public readonly float $totalVat,
        public readonly float $originalPrice,

        // Quantity & Tax
        public readonly int $quantity,
        public readonly float $vatRate,

        // Notes
        public readonly string $comments,

        // Optional fields (must come last in PHP 8+)
        public readonly ?float $costPrice = null,  // Nullable: older orders may not have cost data
        public readonly array $variation = [],
        public readonly array $customFields = [],
    ) {}

    /**
     * @throws InvalidApiResponseException When preorder date format is invalid
     */
    public function toDomain(int $orderExternalId): OrderProduct
    {
        [$isPreorder, $preorderDate] = $this->parsePreorderInfo();

        return new OrderProduct(
            id: $this->id,
            orderExternalId: $orderExternalId,
            title: $this->title,
            sku: $this->sku,
            price: $this->price,
            priceVat: $this->priceVat,
            total: $this->total,
            totalVat: $this->totalVat,
            originalPrice: $this->originalPrice,
            costPrice: $this->costPrice,
            quantity: $this->quantity,
            vatRate: $this->vatRate,
            comments: $this->comments,
            isPreorder: $isPreorder,
            preorderDate: $preorderDate,
            variation: \array_map(ProductVariation::fromArray(...), $this->variation),
            customFields: $this->customFields,
        );
    }

    /**
     * Parse pre-order information from product comments.
     *
     * ShopWired marks pre-order items with comments like "Preorder: 15/01/2026" (DD/MM/YYYY UK format).
     *
     * @return array{0: bool, 1: CarbonImmutable|null} [isPreorder, preorderDate]
     *
     * @throws InvalidApiResponseException When date string exists but fails to parse
     */
    private function parsePreorderInfo(): array
    {
        $lower = \mb_strtolower($this->comments);
        $pos = \mb_strpos($lower, 'preorder:');

        if ($pos === false) {
            // Check for "preorder" without colon/date
            if (\str_contains($lower, 'preorder')) {
                return [true, null];
            }

            return [false, null];
        }

        // Extract everything after "Preorder:" and trim
        $dateStr = \mb_trim(\mb_substr($this->comments, $pos + 9)); // 9 = strlen('preorder:')

        // If there's a date string after "Preorder:", it must be valid
        if ($dateStr !== '') {
            // Carbon returns null on parse failure (not false, and doesn't throw ValueError like DateTimeImmutable)
            $date = CarbonImmutable::createFromFormat(self::PREORDER_DATE_FORMAT, $dateStr);

            if ($date === null) {
                throw new InvalidApiResponseException(
                    'ShopWired',
                    "Invalid preorder date format '{$dateStr}' in product comments. Expected DD/MM/YYYY.",
                );
            }

            return [true, $date->setTime(0, 0)];
        }

        // "Preorder:" with no date is valid
        return [true, null];
    }
}
