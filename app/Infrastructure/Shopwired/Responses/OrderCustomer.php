<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Customer (embedded reference).
 *
 * Always embedded in Standard/Detail modes.
 * Type is int (0-3), semantics unknown - preserved as-is.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderCustomer extends Data
{
    /**
     * @param array<string, mixed> $deviceInfo Attribution data (ipAddress, userAgent, awinChannel, etc.)
     */
    public function __construct(
        public readonly int $id,
        public readonly int $type,
        public readonly ?string $dateOfBirth,
        public readonly array $deviceInfo = [],
    ) {}

    public function toDomain(): \App\Domain\Catalog\Order\ValueObjects\OrderCustomer
    {
        return new \App\Domain\Catalog\Order\ValueObjects\OrderCustomer(
            id: $this->id,
            type: $this->type,
            dateOfBirth: $this->dateOfBirth,
            deviceInfo: $this->deviceInfo,
        );
    }
}
