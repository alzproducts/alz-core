<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * Custom webhook payload for `order.refund.created` events.
 *
 * This is NOT a full order — just the refund details.
 * Parsed from `event.data` in the webhook payload.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderRefundCreatedResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $createdAt,
        public readonly float $amount,
        public readonly string $description,
    ) {}
}
