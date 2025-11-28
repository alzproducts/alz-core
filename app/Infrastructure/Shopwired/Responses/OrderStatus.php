<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Status.
 *
 * Always embedded in Standard/Detail modes - all fields non-nullable.
 * Type is an enum: paid, unpaid, cancelled, shipped, custom.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderStatus extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly int $sortOrder,
    ) {}

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\OrderStatus
    {
        return new \App\Domain\Catalog\Order\ValueObjects\OrderStatus(
            name: OrderStatusType::from($this->name),
            type: $this->type,
        );
    }
}
