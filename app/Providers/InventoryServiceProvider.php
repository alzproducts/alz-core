<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\Inventory\Events\VariantSkusGeneratedEvent;
use App\Infrastructure\Database\Repositories\EloquentSyncCursorRepository;
use App\Infrastructure\Notifications\Listeners\VariantSkusGeneratedSlackListener;
use App\Infrastructure\Shopwired\Repositories\EloquentProductStockRepository;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use RuntimeException;

/**
 * Inventory-related bindings and configuration.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(SyncCursorRepositoryInterface::class, EloquentSyncCursorRepository::class);
        $this->app->bind(ProductStockRepositoryInterface::class, EloquentProductStockRepository::class);

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
     * @throws RuntimeException If event registration fails
     */
    public function boot(): void
    {
        Event::listen(VariantSkusGeneratedEvent::class, VariantSkusGeneratedSlackListener::class);
    }
}
