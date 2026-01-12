<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Refund.
 *
 * Always embedded in Standard/Detail modes when refunds exist.
 * Contains id and created timestamp for database storage, but only
 * name/value are converted to Domain as business-essential fields.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderRefundResponse extends Data
{
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $created = null,
        public readonly ?string $name = null,
        public readonly ?float $value = null,
    ) {}

    public function toDomain(): OrderRefund
    {
        return new OrderRefund(
            name: $this->name ?? '',
            value: $this->value ?? 0.0,
        );
    }
}
