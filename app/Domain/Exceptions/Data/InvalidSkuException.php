<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Override;

/**
 * SKU validation failed.
 *
 * Thrown when a SKU string fails format validation.
 * Valid SKUs: max 40 characters, alphanumeric with hyphens/underscores.
 */
final class InvalidSkuException extends AbstractDataException
{
    public function __construct(
        public readonly string $value,
        public readonly string $reason,
    ) {
        parent::__construct('Invalid SKU');
    }

    #[Override]
    public function context(): array
    {
        return ['value' => $this->value, 'reason' => $this->reason];
    }

    public static function empty(): self
    {
        return new self('', 'SKU cannot be empty');
    }

    public static function tooLong(string $value, int $maxLength): self
    {
        $length = \mb_strlen($value);

        return new self($value, "exceeds maximum length of {$maxLength} characters (got {$length})");
    }

    public static function invalidCharacters(string $value): self
    {
        return new self($value, 'must contain only alphanumeric characters, hyphens, and underscores');
    }

    public static function missingForProvidedType(): self
    {
        return new self('', 'newSku is required when update type is Provided');
    }
}
