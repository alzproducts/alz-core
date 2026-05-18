<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Conversion\ConversionDispatcherInterface;
use App\Infrastructure\Conversion\Dispatchers\QueuedConversionDispatcher;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Conversion Service Provider.
 *
 * Deferred provider for offline-conversion tracking. Binds the platform-agnostic
 * conversion dispatcher; lives in its own bounded context rather than under any
 * specific ad-platform provider — future fan-out (Bing, etc.) will register here.
 */
final class ConversionServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            ConversionDispatcherInterface::class,
            QueuedConversionDispatcher::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ConversionDispatcherInterface::class,
        ];
    }
}
