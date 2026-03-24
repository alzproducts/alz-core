<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use Closure;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Request validation for GET /api/products.
 *
 * Validates pagination bounds and include parameter against an allowlist.
 */
final class ListProductsRequestDTO extends Data
{
    public function __construct(
        #[IntegerType, Min(1), Max(500)]
        public readonly int $per_page = 50,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
        #[Nullable, StringType]
        public readonly ?string $include = null,
    ) {}

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'include' => ['nullable', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! \is_string($value)) {
                    return;
                }

                $requested = \array_map('trim', \explode(',', $value));
                $allowed = self::allowedIncludes();
                $invalid = \array_diff($requested, $allowed);

                if ($invalid !== []) {
                    $fail('The selected include is invalid. Allowed: ' . \implode(', ', $allowed) . '.');
                }
            }],
        ];
    }

    /**
     * Parse and return validated include names.
     *
     * @return list<string>
     */
    public function validatedIncludes(): array
    {
        if ($this->include === null || $this->include === '') {
            return [];
        }

        return \array_map('trim', \explode(',', $this->include));
    }

    /**
     * @return list<string>
     */
    public static function allowedIncludes(): array
    {
        return ['variations'];
    }
}
