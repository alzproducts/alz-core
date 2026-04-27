<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs\ClickUp;

use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use Spatie\LaravelData\Attributes\Validation\ArrayType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * GET /api/clickup/tasks query params.
 *
 * Pass `?refresh=1` to invalidate the 120s task cache before fetching.
 */
final class GetMyClickUpTasksRequestDTO extends Data
{
    /**
     * @param list<string> $statuses
     * @param list<string> $tags
     */
    public function __construct(
        #[ArrayType]
        public readonly array $statuses = [],
        #[ArrayType]
        public readonly array $tags = [],
        public readonly bool $refresh = false,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'statuses' => ['array', 'list'],
            'statuses.*' => ['string'],
            'tags' => ['array', 'list'],
            'tags.*' => ['string'],
        ];
    }

    public function toQueryParams(): ClickUpTaskQueryParams
    {
        return new ClickUpTaskQueryParams(
            statuses: $this->statuses,
            tags: $this->tags,
            forceRefresh: $this->refresh,
        );
    }
}
