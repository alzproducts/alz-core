<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Inventory;

use App\Domain\Exceptions\Infrastructure\AbstractInfrastructureException;
use Throwable;

/**
 * SKU update failed after partial completion.
 *
 * Thrown when SKU update succeeds in one system but fails in another.
 * Contains context needed for manual investigation and recovery.
 */
final class SkuUpdateFailedException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $oldSku,
        public readonly string $newSku,
        public readonly string $failedSystem,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "SKU update failed in {$failedSystem}: {$reason}",
            previous: $previous,
        );
    }
}
