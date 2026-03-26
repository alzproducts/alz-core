<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Traits;

use Illuminate\Validation\Validator;

/**
 * Rejects unknown keys in a `fields` request payload.
 *
 * Use on Spatie Data DTOs that accept a `fields` array with a fixed set of allowed keys.
 * The consuming DTO must define the allowed keys via `allowedFieldKeys()`.
 */
trait RejectsUnknownFieldKeysTrait
{
    /**
     * @return list<string>
     */
    abstract protected static function allowedFieldKeys(): array;

    public static function withValidator(Validator $validator): void
    {
        $validator->after(static function (Validator $validator): void {
            $fields = $validator->getValue('fields');

            if (! \is_array($fields)) {
                return;
            }

            /** @var array<string, mixed> $fields */
            $unknownKeys = \array_diff(\array_keys($fields), static::allowedFieldKeys());

            foreach ($unknownKeys as $key) {
                $validator->errors()->add('fields.' . $key, 'Unknown field: ' . $key);
            }
        });
    }
}
