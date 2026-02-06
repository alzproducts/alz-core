<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Inventory\UseCases\GenerateVariantSkusUseCase;
use App\Domain\Exceptions\InvalidConfigurationException;
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
}
