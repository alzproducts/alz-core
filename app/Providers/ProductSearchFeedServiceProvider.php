<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Contracts\RemoteStorageInterface;
use App\Infrastructure\Feeds\DoofinderFeedProcessor;
use App\Infrastructure\Feeds\DoofinderItemTransformer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Product Search Feed Service Provider.
 *
 * Deferred provider for product search feed processing.
 * Binds feed processor interface to Doofinder implementation.
 */
final class ProductSearchFeedServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ProductSearchFeedProcessorInterface::class,
            static fn(Application $app): ProductSearchFeedProcessorInterface => new DoofinderFeedProcessor(
                storage: $app->make(RemoteStorageInterface::class),
                logger: $app->make(LoggerInterface::class),
                itemTransformer: new DoofinderItemTransformer($app->make(LoggerInterface::class)),
            ),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [ProductSearchFeedProcessorInterface::class];
    }
}
