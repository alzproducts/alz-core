<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Traits;

use Closure;

/**
 * Shared validation logic for the `include` query parameter.
 *
 * DTOs using this trait must:
 * - Define `public readonly ?string $include` property
 * - Implement `allowedIncludes()` returning their endpoint's allowlist
 *
 * Usage in rules(): `return ['include' => self::includeRules(), ...]`
 */
trait ValidatesIncludesTrait
{
    /**
     * @return list<string>
     */
    abstract public static function allowedIncludes(): array;

    /**
     * Validation rules for the `include` query parameter.
     *
     * @return list<mixed>
     */
    public static function includeRules(): array
    {
        return ['nullable', 'string', static function (string $attribute, mixed $value, Closure $fail): void {
            if (! \is_string($value)) {
                return;
            }

            $requested = \array_map('trim', \explode(',', $value));
            $allowed = self::allowedIncludes();
            $invalid = \array_diff($requested, $allowed);

            if ($invalid !== []) {
                $fail('The selected include is invalid. Allowed: ' . \implode(', ', $allowed) . '.');
            }
        }];
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
}
