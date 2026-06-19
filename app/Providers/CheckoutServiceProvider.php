<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Checkout\BasketRecoveryQueryInterface;
use App\Application\Contracts\Checkout\BasketSnapshotRepositoryInterface;
use App\Infrastructure\Ingest\Checkout\Repositories\BasketRecoveryQueryRepository;
use App\Infrastructure\Ingest\Checkout\Repositories\EloquentBasketSnapshotRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Checkout Service Provider.
 *
 * Deferred provider — only loaded when the basket-snapshot binding is resolved
 * (i.e. when the snapshot endpoint is hit). Keeps Octane worker boot lean.
 */
final class CheckoutServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            BasketSnapshotRepositoryInterface::class,
            EloquentBasketSnapshotRepository::class,
        );

        $this->app->singleton(
            BasketRecoveryQueryInterface::class,
            BasketRecoveryQueryRepository::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            BasketRecoveryQueryInterface::class,
            BasketSnapshotRepositoryInterface::class,
        ];
    }
}
