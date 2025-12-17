<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Exceptions\InvalidApiResponseException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use TypeError;
use ValueError;

/**
 * ShopWired API Response: Order Status.
 *
 * Always embedded in Standard/Detail modes - all fields non-nullable.
 * Type is an enum: paid, unpaid, cancelled, shipped, custom.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderStatusResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly int $sortOrder,
    ) {}

    /**
     * Convert to domain value object.
     *
     * @throws InvalidApiResponseException When status name doesn't match known enum values (API contract violation)
     * @throws TypeError When name property type mismatches enum backing type (should not occur with proper Spatie Data parsing)
     */
    public function toDomain(): OrderStatus
    {
        try {
            $statusType = OrderStatusType::from($this->name);
        } catch (ValueError $e) {
            throw new InvalidApiResponseException(
                'ShopWired',
                "Unknown order status name '{$this->name}'. API may have added new status type.",
                $e,
            );
        }

        return new OrderStatus(
            name: $statusType,
            type: $this->type,
        );
    }
}
