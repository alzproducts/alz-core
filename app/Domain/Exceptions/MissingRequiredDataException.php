<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

/**
 * Required data is not available for an operation.
 *
 * Thrown when a business operation cannot proceed because prerequisite data
 * has not been synced or is incomplete. This is a runtime failure, not a bug.
 *
 * Use cases:
 * - Customer trade status not available for order sync (customer sync hasn't run)
 * - Product data missing for inventory update
 * - Any cross-system data dependency that isn't satisfied
 *
 * Resolution: Run the prerequisite sync job first, then retry.
 */
final class MissingRequiredDataException extends DomainException
{
    /**
     * @param string $dataType Type of data that's missing (e.g., 'customer trade status')
     * @param string $operation Operation that requires the data (e.g., 'Mixpanel order sync')
     * @param string|null $resolution Suggested resolution (e.g., 'Run customer sync first')
     */
    public function __construct(
        public readonly string $dataType,
        public readonly string $operation,
        public readonly ?string $resolution = null,
        ?Throwable $previous = null,
    ) {
        $message = "Required {$dataType} data not available for {$operation}";

        if ($resolution !== null) {
            $message .= ". {$resolution}";
        }

        parent::__construct($message, previous: $previous);
    }
}
