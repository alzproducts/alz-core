<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Refund.
 *
 * Always embedded in Standard/Detail modes when refunds exist.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderRefundResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $created = null,
        public readonly ?string $name = null,
        public readonly ?float $value = null,
    ) {}

    public function toDomain(): OrderRefund
    {
        return new OrderRefund(
            externalId: $this->id,
            name: $this->name ?? '',
            value: $this->value ?? 0.0,
            createdAt: $this->parseCreatedAt() ?? new DateTimeImmutable(),
        );
    }

    private function parseCreatedAt(): ?CarbonImmutable
    {
        if ($this->created === null || $this->created === '') {
            return null;
        }

        // Carbon returns null on parse failure (unlike DateTimeImmutable which throws)
        return CarbonImmutable::parse($this->created);
    }
}
