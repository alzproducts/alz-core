<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use Throwable;

/**
 * Thrown when a stock update operation fails.
 *
 * Captures the expected vs actual update counts, the items that were
 * attempted, and a reason for the failure. Used when:
 * - API reports fewer updates than items sent (SKU may not exist)
 * - Batch processing partially fails
 * - Validation errors in update payload
 *
 * This is NOT a transient failure - the stock state may be inconsistent.
 * Requires investigation before retry.
 *
 * Note: $attemptedItems contains ALL items sent, not just the failed ones.
 * The API doesn't report per-item success/failure, only aggregate counts.
 * To identify problematic SKUs, verify each against the target system.
 */
final class StockUpdateFailedException extends AbstractInfrastructureException
{
    /**
     * @param list<ItemStockLevel> $attemptedItems All items that were sent for update
     */
    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
        public readonly string $reason,
        public readonly array $attemptedItems,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Stock update failed: {$reason}", 0, $previous);
    }
}
