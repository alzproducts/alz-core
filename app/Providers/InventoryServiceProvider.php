<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Inventory\InventoryDispatcherInterface;
use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Database\Repositories\EloquentSyncCursorRepository;
use App\Infrastructure\Linnworks\Dispatchers\QueuedInventoryDispatcher;
use App\Infrastructure\Shopwired\Repositories\EloquentProductStockRepository;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Inventory-related bindings.
 *
 * Deferred — event wiring lives in EventServiceProvider.
 */
final class InventoryServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(SyncCursorRepositoryInterface::class, EloquentSyncCursorRepository::class);
        $this->app->bind(ProductStockRepositoryInterface::class, EloquentProductStockRepository::class);
        $this->app->singleton(InventoryDispatcherInterface::class, QueuedInventoryDispatcher::class);

        $this->registerStandardSignProductId();
    }

    private function registerStandardSignProductId(): void
    {
        $this->app->when(GenerateVariantSkusUseCase::class)
            ->needs('$standardSignProductId')
            ->give(static function (): int {
                $value = \config('shopwired.standard_sign_product_id');

                if (! \is_numeric($value)) {
                    throw new InvalidConfigurationException(
                        'shopwired.standard_sign_product_id',
                        'SHOPWIRED_STANDARD_SIGN_PRODUCT_ID must be set in .env',
                    );
                }

                return (int) $value;
            });
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            InventoryDispatcherInterface::class,
            SyncCursorRepositoryInterface::class,
            ProductStockRepositoryInterface::class,
        ];
    }
}
