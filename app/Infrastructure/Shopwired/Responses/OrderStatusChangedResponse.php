<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

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
}
