<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Admin Comment.
 *
 * Infrastructure DTO for parsing admin comment data from order responses.
 * This is NOT converted to a Domain VO as admin comments are ShopWired-specific
 * and have no current business logic use case.
 *
 * Note: `status_id` uses snake_case in the API (unlike other fields).
 *
 * @see https://shopwired.readme.io/reference/listorders
 */
#[MapInputName(SnakeCaseMapper::class)]
final class OrderAdminCommentResponse extends Data
{
    public function __construct(
        public readonly ?int $id = null,
        public readonly ?string $created = null,
        public readonly ?string $content = null,
        #[MapInputName('status_id')]
        public readonly ?int $statusId = null,
    ) {}
}
