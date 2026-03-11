<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Custom webhook payload for `product.stock_changed` events.
 *
 * This is NOT a full product — just the stock change details.
 * Parsed from `event.data` in the webhook payload.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
#[MapInputName(SnakeCaseMapper::class)]
final class ProductStockChangedResponse extends Data
{
    public function __construct(
        public readonly string $sku,
        public readonly bool $isVariation,
        public readonly int $newQuantity,
        public readonly ?int $orderId = null,
    ) {}
}
