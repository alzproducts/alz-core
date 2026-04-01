<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\EscalationsConfigRepositoryInterface;
use App\Application\Contracts\Operations\PricePeriodRepositoryInterface;
use App\Application\Contracts\Operations\SkuChangeRepositoryInterface;
use App\Infrastructure\Database\DatabaseGateway;
use App\Infrastructure\Operations\Repositories\EloquentPricePeriodRepository;
use App\Infrastructure\Operations\Repositories\EloquentSkuChangeRepository;
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
        $this->registerGateway();
        $this->registerRepositories();
    }

    private function registerGateway(): void
    {
        $this->app->singleton(
            DatabaseGatewayInterface::class,
            static fn(Application $app): DatabaseGatewayInterface => new DatabaseGateway(
                $app->make(LoggerInterface::class),
                $app->make(DatabaseManager::class),
            ),
        );

        $this->app->singleton(
            DatabaseGateway::class,
            static fn(Application $app): DatabaseGateway => $app->make(DatabaseGatewayInterface::class),
        );
    }

    private function registerRepositories(): void
    {
        $this->app->bind(EscalationsConfigRepositoryInterface::class, EscalationsConfigRepository::class);
        $this->app->bind(PricePeriodRepositoryInterface::class, EloquentPricePeriodRepository::class);
        $this->app->bind(SkuChangeRepositoryInterface::class, EloquentSkuChangeRepository::class);
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
            PricePeriodRepositoryInterface::class,
            SkuChangeRepositoryInterface::class,
        ];
    }
}
