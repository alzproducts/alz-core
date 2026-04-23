<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use Override;

/**
 * Thrown when provided data is insufficient for an operation.
 *
 * Use cases:
 * - Customer requires at least email or phone for identification
 * - Order requires at least one line item
 * - Any "at least one of X or Y" validation
 *
 * This is for input validation, not missing sync data.
 *
 * @see MissingRequiredDataException For cross-system data dependencies
 */
final class InsufficientDataException extends AbstractDataException
{
    /**
     * @param string $entityType The entity/context (e.g., "Customer", "Order")
     * @param string $requirement What's needed (e.g., "at least an email or phone number")
     */
    public function __construct(
        public readonly string $entityType,
        public readonly string $requirement,
    ) {
        parent::__construct('Insufficient data for operation');
    }

    #[Override]
    public function context(): array
    {
        return ['entity_type' => $this->entityType, 'requirement' => $this->requirement];
    }
}
