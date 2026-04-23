<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use Override;
use Throwable;

/**
 * Thrown when a storage operation (upload, download, delete) fails.
 *
 * Use cases:
 * - S3 upload failure (permissions, network)
 * - File write failure (disk full, permissions)
 * - Storage service unavailable
 */
final class StorageOperationFailedException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $path,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Storage operation failed', previous: $previous);
    }

    #[Override]
    public function context(): array
    {
        return [
            'operation' => $this->operation,
            'path' => $this->path,
            'reason' => $this->reason,
        ];
    }
}
