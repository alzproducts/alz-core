<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\ProductSearchFeedProcessorInterface;
use App\Application\Contracts\RemoteStorageInterface;
use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Feeds\DoofinderConfig;
use App\Infrastructure\Feeds\DoofinderFeedProcessor;
use App\Infrastructure\Feeds\DoofinderItemTransformer;
use App\Infrastructure\Feeds\Fakes\FailClosedProductSearchFeedProcessor;
use App\Infrastructure\Integrations\LiveIntegration;
use App\Infrastructure\Integrations\LiveIntegrationGate;
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
        $this->app->singleton(DoofinderConfig::class, static fn(): DoofinderConfig => self::createConfig());
        $this->registerProcessor();

        $this->app->when(ProcessProductSearchFeedUseCase::class)
            ->needs('$sourceUrl')
            ->give(static fn(Application $app): string => $app->make(DoofinderConfig::class)->sourceUrl);

        $this->app->when(ProcessProductSearchFeedUseCase::class)
            ->needs('$storagePath')
            ->give(static fn(Application $app): string => $app->make(DoofinderConfig::class)->storagePath);
    }

    private function registerProcessor(): void
    {
        $this->app->singleton(
            ProductSearchFeedProcessorInterface::class,
            static fn(Application $app): ProductSearchFeedProcessorInterface => $app->make(LiveIntegrationGate::class)->isLive(LiveIntegration::Doofinder)
                ? new DoofinderFeedProcessor(
                    storage: $app->make(RemoteStorageInterface::class),
                    logger: $app->make(LoggerInterface::class),
                    itemTransformer: new DoofinderItemTransformer($app->make(LoggerInterface::class)),
                )
                : $app->make(FailClosedProductSearchFeedProcessor::class),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [DoofinderConfig::class, ProductSearchFeedProcessorInterface::class, ProcessProductSearchFeedUseCase::class];
    }

    private static function createConfig(): DoofinderConfig
    {
        /** @var mixed $config */
        $config = \config('feeds.doofinder');

        if (! \is_array($config)) {
            throw new InvalidConfigurationException('feeds.doofinder', 'Product search feed configuration is missing');
        }

        return new DoofinderConfig(
            sourceUrl: \is_string($config['source_url'] ?? null) ? $config['source_url'] : '',
            storagePath: \is_string($config['storage_path'] ?? null) ? $config['storage_path'] : '',
            storageDisk: \is_string($config['storage_disk'] ?? null) ? $config['storage_disk'] : '',
        );
    }
}
