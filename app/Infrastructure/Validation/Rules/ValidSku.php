<?php

declare(strict_types=1);

namespace App\Infrastructure\Validation\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final readonly class ValidSku implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!\is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (\preg_match('/^[A-Za-z0-9\s\-_.()\/]+$/', $value) !== 1) {
            $fail('The :attribute contains invalid characters.');
        }
    }
}
