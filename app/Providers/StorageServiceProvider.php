<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\RemoteStorageInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Storage\S3StorageClient;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Storage Service Provider.
 *
 * Deferred provider for remote storage services. Binds RemoteStorageInterface
 * to S3StorageClient using the configured remote disk.
 */
final class StorageServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            RemoteStorageInterface::class,
            static fn(Application $app): RemoteStorageInterface => self::createStorageClient($app),
        );
    }

    private static function createStorageClient(Application $app): RemoteStorageInterface
    {
        /** @var mixed $disk */
        $disk = \config('filesystems.remote');

        if (! \is_string($disk) || ($disk === '')) {
            throw new InvalidConfigurationException(
                'filesystems.remote',
                'Remote storage disk not configured',
            );
        }

        return new S3StorageClient(
            disk: $disk,
            filesystemFactory: $app->make(FilesystemFactory::class),
            logger: $app->make(LoggerInterface::class),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [RemoteStorageInterface::class];
    }
}
