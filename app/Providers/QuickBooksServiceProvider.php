<?php

declare(strict_types=1);

namespace App\Providers;

use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksConfigFactory;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Override;

/**
 * Deferred: boot never resolves the QBO config, so blank keys fail on first use (ADR-0015).
 */
final class QuickBooksServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            QuickBooksConfig::class,
            static fn(): QuickBooksConfig => QuickBooksConfigFactory::create(),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            QuickBooksConfig::class,
        ];
    }
}
