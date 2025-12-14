<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderDiscount;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Discount.
 *
 * Always embedded in Standard/Detail modes when discounts exist.
 * IDs (voucherId, offerId) needed for Mixpanel tracking.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderDiscountResponse extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly float $value,
        public readonly ?string $type = null,
        public readonly ?string $code = null,
        public readonly ?int $voucherId = null,
        public readonly ?int $offerId = null,
    ) {}

    public function toDomain(): OrderDiscount
    {
        return new OrderDiscount(
            name: $this->name,
            value: $this->value,
            type: $this->type,
            code: $this->code,
            voucherId: $this->voucherId,
            offerId: $this->offerId,
        );
    }
}
