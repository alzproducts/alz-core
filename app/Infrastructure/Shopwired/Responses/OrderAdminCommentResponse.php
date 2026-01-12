<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderAdminComment;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Order Admin Comment.
 *
 * Infrastructure DTO for parsing admin comment data from order responses.
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
            externalId: $this->id ?? 0,
            content: $this->content ?? '',
            createdAt: $this->parseCreatedAt() ?? new DateTimeImmutable(),
            statusId: $this->statusId,
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
