<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Persistence\Repositories\EscalationsConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Database Service Provider.
 *
 * Deferred provider for database gateway and related repositories.
 * Services are only loaded when requested, allowing other features to function independently.
 */
final class DatabaseServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->singleton(
            DatabaseGatewayInterface::class,
            static fn(Application $app): DatabaseGatewayInterface => new DatabaseGateway(
                $app->make(LoggerInterface::class),
                $app->make(DatabaseManager::class),
            ),
        );

        // Also bind concrete class for repositories that need connection() access
        $this->app->singleton(
            DatabaseGateway::class,
            static fn(Application $app): DatabaseGateway => $app->make(DatabaseGatewayInterface::class),
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
            DatabaseGatewayInterface::class,
            DatabaseGateway::class,
            EscalationsConfigRepositoryInterface::class,
        ];
    }
}
