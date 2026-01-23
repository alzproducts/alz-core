<?php

declare(strict_types=1);

namespace App\Infrastructure\Storage;

use App\Application\Contracts\RemoteStorageInterface;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToWriteFile;
use Psr\Log\LoggerInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * S3 storage client using Laravel Flysystem.
 *
 * Wraps Laravel's filesystem with proper exception translation
 * for Clean Architecture compliance. The disk is configured at
 * construction time via the Service Provider.
 */
final readonly class S3StorageClient implements RemoteStorageInterface
{
    private const string SERVICE_NAME = 'S3 Storage';

    public function __construct(
        private string $disk,
        private FilesystemFactory $filesystemFactory,
        private LoggerInterface $logger,
    ) {}

    public function put(string $path, string $content): void
    {
        try {
            $this->getFilesystem()->put($path, $content);

            $this->logger->debug('File uploaded to storage', [
                'disk' => $this->disk,
                'path' => $path,
                'size_bytes' => \mb_strlen($content),
            ]);
        } catch (UnableToWriteFile $e) {
            $this->logStorageError('upload', $path, $e);
            throw new StorageOperationFailedException(
                operation: 'upload',
                path: $path,
                reason: 'Failed to write file to storage',
                previous: $e,
            );
        } catch (Throwable $e) {
            $this->logStorageError('upload', $path, $e);
            throw new StorageOperationFailedException(
                operation: 'upload',
                path: $path,
                reason: $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function temporaryUrl(string $path, DateTimeImmutable $expiration): string
    {
        try {
            $url = $this->getFilesystem()->temporaryUrl($path, $expiration);

            $this->logger->debug('Generated temporary URL', [
                'disk' => $this->disk,
                'path' => $path,
                'expires' => $expiration->format('c'),
            ]);

            return $url;
        } catch (Throwable $e) {
            $this->logStorageError('temporaryUrl', $path, $e);
            throw new StorageOperationFailedException(
                operation: 'temporaryUrl',
                path: $path,
                reason: $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function exists(string $path): bool
    {
        try {
            return $this->getFilesystem()->exists($path);
        } catch (UnableToCheckExistence $e) {
            $this->logStorageError('exists', $path, $e);
            throw new StorageOperationFailedException(
                operation: 'exists',
                path: $path,
                reason: 'Failed to check file existence',
                previous: $e,
            );
        } catch (Throwable $e) {
            $this->logStorageError('exists', $path, $e);
            throw new StorageOperationFailedException(
                operation: 'exists',
                path: $path,
                reason: $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Get the filesystem adapter for the configured disk.
     */
    private function getFilesystem(): FilesystemAdapter
    {
        $filesystem = $this->filesystemFactory->disk($this->disk);
        Assert::isInstanceOf($filesystem, FilesystemAdapter::class);

        return $filesystem;
    }

    /**
     * Log storage operation errors with consistent format.
     */
    private function logStorageError(string $operation, string $path, Throwable $e): void
    {
        $this->logger->error('Storage operation failed', [
            'service' => self::SERVICE_NAME,
            'operation' => $operation,
            'disk' => $this->disk,
            'path' => $path,
            'error' => $e->getMessage(),
            'exception_class' => $e::class,
        ]);
    }
}
