<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Inventory-related bindings and configuration.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // Inject standard sign product ID from config (nullable when not configured)
        $this->app->when(GenerateVariantSkusUseCase::class)
            ->needs('$standardSignProductId')
            ->give(static function (): ?int {
                $value = \config('shopwired.standard_sign_product_id');

                return \is_numeric($value) ? (int) $value : null;
            });
    }
}
