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

    /**
     * @throws StorageOperationFailedException
     */
    public function put(string $path, string $content): void
    {
        try {
            $this->getFilesystem()->put($path, $content);

            $this->logger->debug('File uploaded to storage', [
                'disk' => $this->disk,
                'path' => $path,
                'size_bytes' => \mb_strlen($content),
            ]);
        } catch (Throwable $e) {
            throw $this->buildStorageException('upload', $path, $e);
        }
    }

    /**
     * @throws StorageOperationFailedException
     */
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
            throw $this->buildStorageException('temporaryUrl', $path, $e);
        }
    }

    /**
     * @throws StorageOperationFailedException
     */
    public function exists(string $path): bool
    {
        try {
            return $this->getFilesystem()->exists($path);
        } catch (Throwable $e) {
            throw $this->buildStorageException('exists', $path, $e);
        }
    }

    private function getFilesystem(): FilesystemAdapter
    {
        $filesystem = $this->filesystemFactory->disk($this->disk);
        Assert::isInstanceOf($filesystem, FilesystemAdapter::class);

        return $filesystem;
    }

    /**
     * Log and build a storage exception for translation to domain layer.
     */
    private function buildStorageException(string $operation, string $path, Throwable $e): StorageOperationFailedException
    {
        $this->logger->error('Storage operation failed', [
            'service' => self::SERVICE_NAME,
            'operation' => $operation,
            'disk' => $this->disk,
            'path' => $path,
            'error' => $e->getMessage(),
            'exception_class' => $e::class,
        ]);

        $reason = match (true) {
            $e instanceof UnableToWriteFile => 'Failed to write file to storage',
            $e instanceof UnableToCheckExistence => 'Failed to check file existence',
            default => $e->getMessage(),
        };

        return new StorageOperationFailedException(
            operation: $operation,
            path: $path,
            reason: $reason,
            previous: $e,
        );
    }
}
