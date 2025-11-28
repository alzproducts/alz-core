<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Discount.
 *
 * Infrastructure DTO for parsing discount data from order responses.
 * Includes IDs (voucherId, offerId) needed for Mixpanel tracking.
 *
 * Domain conversion will be added after smoke testing validates parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderDiscount extends Data
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?float $value = null,
        public readonly ?string $type = null,
        public readonly ?string $code = null,
        public readonly ?int $voucherId = null,
        public readonly ?int $offerId = null,
    ) {}
}
