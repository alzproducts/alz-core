<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Catalog\Order\ValueObjects\OrderStatusType;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use TypeError;
use ValueError;

/**
 * Custom webhook payload for `order.status_changed` events.
 *
 * This is NOT a full order — just the new status fields.
 * Parsed from `event.data.newStatus` in the webhook payload.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderStatusChangedResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,
        public readonly int $sortOrder,
    ) {}

    /** @throws InvalidApiResponseException When status name doesn't match known enum values */
    public function toDomain(): OrderStatus
    {
        try {
            $statusType = OrderStatusType::from($this->name);
        } catch (ValueError|TypeError $e) {
            throw new InvalidApiResponseException(
                'ShopWired',
                "Unknown order status name '{$this->name}'. API may have added new status type.",
                $e,
            );
        }

        return new OrderStatus(
            id: $this->id,
            name: $statusType,
            type: $this->type,
            sortOrder: $this->sortOrder,
        );
    }
}
