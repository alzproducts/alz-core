<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Tax.
 *
 * Infrastructure DTO for parsing tax data from order responses.
 * This is NOT converted to a Domain VO as tax details are ShopWired-specific
 * and have no current business logic use case.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderTaxResponse extends Data
{
    public function __construct(
        public readonly ?string $type = null,
        public readonly ?float $value = null,
    ) {}
}
