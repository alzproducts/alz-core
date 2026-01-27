<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

/**
 * All items in a batch operation failed.
 *
 * Thrown when a batch operation results in 100% failure. This allows
 * distinguishing between partial failures (which may be logged but continue)
 * and total failures (which should trigger retry mechanisms).
 *
 * @template TResult of object
 */
final class AllItemsFailedException extends DomainException
{
    /**
     * @param TResult $result The result object containing failure details
     * @param int $totalItems Number of items that failed
     */
    public function __construct(
        public readonly object $result,
        public readonly int $totalItems,
    ) {
        parent::__construct("All {$totalItems} items in batch failed");
    }
}
