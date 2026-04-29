<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Shipping.
 *
 * `name` is nullable: staff can create an order without selecting a shipping method,
 * in which case ShopWired sends a shipping line with a null (or empty-string) name.
 * Note: API returns shipping as array; use Order::getFirstShipping().
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderShippingResponse extends Data
{
    public function __construct(
        public readonly ?string $name,
        public readonly float $value,
        public readonly float $vatRate,
        public readonly ?int $id = null,
    ) {}

    public function toDomain(): OrderShipping
    {
        return new OrderShipping(
            id: $this->id,
            name: $this->name !== '' ? $this->name : null,
            chargeNet: $this->value,
            vatRate: $this->vatRate,
        );
    }
}
