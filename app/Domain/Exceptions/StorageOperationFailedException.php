<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use Throwable;

/**
 * Thrown when a storage operation (upload, download, delete) fails.
 *
 * Use cases:
 * - S3 upload failure (permissions, network)
 * - File write failure (disk full, permissions)
 * - Storage service unavailable
 */
final class StorageOperationFailedException extends DomainException
{
    public function __construct(
        public readonly string $operation,
        public readonly string $path,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "Storage {$operation} failed for '{$path}': {$reason}",
            previous: $previous,
        );
    }
}
