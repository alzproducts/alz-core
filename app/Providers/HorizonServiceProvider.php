<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Override;
use RuntimeException;

final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     * Access is controlled via HorizonBasicAuth middleware, so we allow all
     * requests regardless of Laravel authentication status ($user = null).
     * BasicAuth has already verified credentials before this gate is checked.
     *
     * @throws RuntimeException When gate registration fails
     */
    #[Override]
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn(mixed $user = null): bool => true);
    }
}
