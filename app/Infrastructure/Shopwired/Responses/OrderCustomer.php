<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Customer (embedded reference).
 *
 * Infrastructure DTO for parsing customer reference from order responses.
 * This is a lightweight reference (not full customer data).
 *
 * Note: `type` is a string ('guest' or 'registered'), not an int.
 * Domain conversion will be added after smoke testing validates parsing.
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderCustomer extends Data
{
    /**
     * @param array<string, mixed>|null $deviceInfo ipAddress, userAgent, facebookBrowserId, facebookClickId
     */
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $type = null,
        public readonly ?string $dateOfBirth = null,
        public readonly ?array $deviceInfo = null,
    ) {}
}
