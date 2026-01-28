<?php

declare(strict_types=1);

namespace App\Application\Contracts\Operations;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Inventory\Enums\SkuUpdateReason;

/**
 * Audit repository for SKU change tracking.
 *
 * Records use business identifiers (SKUs) only - no external system IDs.
 */
interface SkuChangeRepositoryInterface
{
    /**
     * Create an audit record before attempting the update.
     *
     * @return string UUID of the created audit record
     *
     * @throws DatabaseOperationFailedException On insert failure
     */
    public function create(
        string $oldSku,
        Sku $newSku,
        SkuUpdateReason $reason,
    ): string;

    /**
     * Mark an update as successfully completed.
     *
     * @param string $id UUID of the audit record
     *
     * @throws DatabaseOperationFailedException On update failure
     */
    public function markComplete(string $id): void;

    /**
     * Record an error message for a failed update.
     *
     * @param string $id UUID of the audit record
     *
     * @throws DatabaseOperationFailedException On update failure
     */
    public function recordError(string $id, string $errorMessage): void;
}
