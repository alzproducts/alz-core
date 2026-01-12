<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Admin Comment.
 *
 * Infrastructure DTO for parsing admin comment data from order responses.
 * Contains id and created timestamp for database storage, but only
 * content/statusId are converted to Domain as business-essential fields.
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

    public function toDomain(): OrderAdminComment
    {
        return new OrderAdminComment(
            content: $this->content ?? '',
            statusId: $this->statusId,
        );
    }
}
