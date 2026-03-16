<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Data;

/**
 * Custom webhook payload for `order.refund.created` events.
 *
 * This is NOT a full order — just the refund details.
 * Parsed from `event.data.object` in the webhook payload.
 *
 * Note: No SnakeCaseMapper — refund payloads use camelCase keys natively.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
final class OrderRefundCreatedResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly int $orderId,
        public readonly string $createdAt,
        public readonly float $amount,
        public readonly string $description,
    ) {}

    /**
     * @throws InvalidApiResponseException When createdAt is not a valid date string
     */
    public function toDomain(): OrderRefund
    {
        try {
            return new OrderRefund(
                externalId: $this->id,
                name: $this->description,
                value: $this->amount,
                createdAt: new DateTimeImmutable($this->createdAt),
            );
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
