<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderCustomer;
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
final class OrderCustomerResponse extends Data
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

    public function toDomain(): OrderCustomer
    {
        return new OrderCustomer(
            id: $this->id,
            type: $this->type,
            dateOfBirth: $this->dateOfBirth,
            deviceInfo: $this->deviceInfo,
        );
    }
}
