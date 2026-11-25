<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Data;

final class SyncQuickBooksOrdersRequestDTO extends Data
{
    /**
     * @param non-empty-list<int> $references
     */
    public function __construct(
        public readonly array $references,
        #[BooleanType]
        public readonly bool $force_retry = false,
    ) {}

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'references' => ['required', 'array', 'list', 'min:1', 'max:10'],
            'references.*' => ['integer:strict', 'min:1', 'max:2147483647', 'distinct'],
        ];
    }
}
