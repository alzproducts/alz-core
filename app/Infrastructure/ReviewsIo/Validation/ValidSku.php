<?php

declare(strict_types=1);

namespace App\Infrastructure\ReviewsIo\Validation;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates SKU format for e-commerce product identifiers.
 *
 * Allows alphanumeric characters plus common e-commerce SKU special characters:
 * - Spaces, hyphens, underscores, dots, parentheses, forward slashes
 * - Example valid SKUs: "FLP-01", "E2L-PA481101", "SKU-01_v2.0(new)/final"
 *
 * Security: Prevents injection of HTML/script tags (<, >, &, etc.) and other
 * potentially dangerous characters in product identifiers.
 */
final readonly class ValidSku implements ValidationRule
{
    /**
     * Regex pattern for valid SKU characters.
     * Allows: alphanumeric, spaces, hyphens, underscores, dots, parentheses, forward slashes.
     * Security: Rejects HTML tags, ampersands, percent signs, and other injection vectors.
     */
    private const string PATTERN = '/^[A-Za-z0-9\s\-_.()\/]+$/';

    /** @noinspection PhpUnusedParameterInspection */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!\is_string($value)) {
            $fail('The :attribute must be a string.');

            return;
        }

        if (\preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute contains invalid characters.');
        }
    }
}
