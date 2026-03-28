<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use LogicException;

/**
 * Thrown when a field name is not supported by the target operation.
 *
 * Extends LogicException because field names are coded constants, not user input.
 * If this fires, it means a DTO allows a key that the use case doesn't handle —
 * a programming error, not a runtime condition.
 */
final class UnsupportedFieldException extends LogicException
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $entityType,
    ) {
        parent::__construct('Unsupported field for entity');
    }

    /**
     * @return array<string, string>
     */
    public function context(): array
    {
        return [
            'field_name' => $this->fieldName,
            'entity_type' => $this->entityType,
        ];
    }
}
