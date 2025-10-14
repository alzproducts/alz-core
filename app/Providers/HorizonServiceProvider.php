<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Access is controlled via HorizonBasicAuth middleware, not user-based authorization.
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn(): bool => false);
    }
}
