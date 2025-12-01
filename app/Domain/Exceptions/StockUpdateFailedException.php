<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

/**
 * Thrown when a stock update operation fails.
 *
 * Captures the expected vs actual update counts and a reason
 * for the failure. Used when:
 * - API reports fewer updates than items sent
 * - Batch processing partially fails
 * - Validation errors in update payload
 *
 * This is NOT a transient failure - the stock state may be inconsistent.
 * Requires investigation before retry.
 */
final class StockUpdateFailedException extends DomainException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Stock update failed: {$reason}", 0, $previous);
    }
}
