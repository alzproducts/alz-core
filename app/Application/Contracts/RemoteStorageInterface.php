<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use DateTimeImmutable;

/**
 * Contract for remote file storage operations.
 *
 * Abstracts cloud storage (S3, GCS, etc.) behind a simple interface.
 * Implementations handle SDK specifics, error translation, and logging.
 * The storage disk is configured at construction time via the DI container.
 */
interface RemoteStorageInterface
{
    /**
     * Upload content to remote storage.
     *
     * @param string $path    Path within the storage (e.g., 'feeds/output.xml')
     * @param string $content File content to upload
     *
     * @throws StorageOperationFailedException When upload fails
     */
    public function put(string $path, string $content): void;

    /**
     * Generate a temporary signed URL for private file access.
     *
     * @param string            $path       Path within the storage
     * @param DateTimeImmutable $expiration When the URL should expire
     *
     * @return string Signed URL for temporary access
     *
     * @throws StorageOperationFailedException When URL generation fails or file doesn't exist
     */
    public function temporaryUrl(string $path, DateTimeImmutable $expiration): string;

    /**
     * Check if a file exists at the given path.
     *
     * @param string $path Path within the storage
     *
     * @throws StorageOperationFailedException When existence check fails
     */
    public function exists(string $path): bool;
}
