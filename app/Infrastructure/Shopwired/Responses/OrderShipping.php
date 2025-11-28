<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Shipping.
 *
 * Always embedded in Standard/Detail modes - all fields non-nullable.
 * Note: API returns shipping as array; use Order::getFirstShipping().
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderShipping extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly float $value,
        public readonly float $vatRate,
    ) {}

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\OrderShipping
    {
        return new \App\Domain\Catalog\Order\ValueObjects\OrderShipping(
            name: $this->name,
            value: $this->value,
            vatRate: $this->vatRate,
        );
    }
}
