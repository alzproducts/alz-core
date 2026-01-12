<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\DatabaseClientInterface;
use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Infrastructure\Supabase\EscalationsConfigRepository;
use App\Infrastructure\Supabase\SupabaseClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Supabase Service Provider.
 *
 * Deferred provider for Supabase database services. Services are only loaded
 * when requested, allowing other features to function independently.
 */
final class SupabaseServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            DatabaseClientInterface::class,
            static fn(Application $app): DatabaseClientInterface => new SupabaseClient(
                $app->make(LoggerInterface::class),
                $app->make(DatabaseManager::class),
            ),
        );

        $this->app->bind(
            EscalationsConfigRepositoryInterface::class,
            EscalationsConfigRepository::class,
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            DatabaseClientInterface::class,
            EscalationsConfigRepositoryInterface::class,
        ];
    }
}
