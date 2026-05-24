<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Override;

/**
 * A value does not match the expected format for its type.
 *
 * Thrown when a string value fails pattern validation (e.g., click IDs,
 * tracking parameters). Carries the field name and rejected value for
 * structured logging.
 */
final class InvalidFormatException extends AbstractDataException
{
    public function __construct(
        public readonly string $field,
        public readonly string $value,
    ) {
        parent::__construct('Invalid format');
    }

    #[Override]
    public function context(): array
    {
        return ['field' => $this->field, 'value' => $this->value];
    }
}
