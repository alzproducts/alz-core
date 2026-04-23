<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Inventory;

use App\Domain\Exceptions\Infrastructure\AbstractInfrastructureException;
use Override;
use Throwable;

/**
 * Failed to generate a new SKU from Linnworks.
 *
 * Thrown when GetNewItemNumber API fails. This is a transient failure
 * that can be retried.
 */
final class SkuGenerationFailedException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Failed to generate new SKU', previous: $previous);
    }

    #[Override]
    public function context(): array
    {
        return ['reason' => $this->reason];
    }
}
