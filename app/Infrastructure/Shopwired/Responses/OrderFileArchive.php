<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order File Archive.
 *
 * Infrastructure DTO for parsing file archive data from order responses.
 * This is NOT converted to a Domain VO as file archives are ShopWired-specific
 * and have no current business logic use case.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderFileArchive extends Data
{
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $url = null,
    ) {}
}
