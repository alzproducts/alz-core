<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\ClickUp\UseCases\CompleteClickUpTaskUseCase;
use App\Application\ClickUp\UseCases\GetMyClickUpTasksUseCase;
use App\Application\Contracts\Access\ApiKeyCipherInterface;
use App\Application\Contracts\Access\UserApiKeyRepositoryInterface;
use App\Application\Contracts\ClickUp\ClickUpUserCacheInterface;
use App\Application\Contracts\ClickUp\TasksClientInterface;
use App\Application\Contracts\ClickUp\UsersClientInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Access\EloquentUserApiKeyRepository;
use App\Infrastructure\Access\OpensslApiKeyCipher;
use App\Infrastructure\ClickUp\Cache\ClickUpUserCache;
use App\Infrastructure\ClickUp\ClickUpConfig;
use App\Infrastructure\ClickUp\ClickUpHttpTransport;
use App\Infrastructure\ClickUp\Clients\TasksClient;
use App\Infrastructure\ClickUp\Clients\UsersClient;
use App\Infrastructure\Persistence\EloquentGateway;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Override;
use Psr\Log\LoggerInterface;

/**
 * ClickUp API and user API key service provider.
 *
 * Deferred — only loads when a bound class is first resolved.
 */
final class ClickUpServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->registerCipher();
        $this->registerRepository();
        $this->registerUserCache();
        $this->registerClients();
        $this->registerUseCases();
    }

    private function registerCipher(): void
    {
        $this->app->singleton(ApiKeyCipherInterface::class, static function (): OpensslApiKeyCipher {
            $keyHex = \config('app.api_key_encryption_secret', '');

            return new OpensslApiKeyCipher(\is_string($keyHex) ? $keyHex : '');
        });
    }

    private function registerRepository(): void
    {
        $this->app->singleton(
            UserApiKeyRepositoryInterface::class,
            static fn(Application $app): EloquentUserApiKeyRepository => new EloquentUserApiKeyRepository(
                $app->make(EloquentGateway::class),
                $app->make(ApiKeyCipherInterface::class),
            ),
        );
    }

    private function registerUserCache(): void
    {
        $this->app->singleton(
            ClickUpUserCacheInterface::class,
            static fn(Application $app): ClickUpUserCache => new ClickUpUserCache(
                $app->make(CacheRepository::class),
            ),
        );
    }

    private function registerClients(): void
    {
        $this->app->singleton(ClickUpConfig::class, static fn(): ClickUpConfig => new ClickUpConfig(
            baseUrl: self::configString('clickup.base_url', 'https://api.clickup.com/api/v2'),
            timeoutSeconds: self::configInt('clickup.timeout_seconds', 15),
        ));

        $this->app->singleton(
            ClickUpHttpTransport::class,
            static fn(Application $app): ClickUpHttpTransport => new ClickUpHttpTransport(
                $app->make(ClickUpConfig::class),
                $app->make(HttpFactory::class),
            ),
        );

        $this->registerHttpClients();
    }

    private function registerHttpClients(): void
    {
        $this->app->singleton(
            UsersClientInterface::class,
            static fn(Application $app): UsersClient => new UsersClient(
                $app->make(ClickUpHttpTransport::class),
            ),
        );

        $this->app->singleton(
            TasksClientInterface::class,
            static fn(Application $app): TasksClient => new TasksClient(
                $app->make(ClickUpHttpTransport::class),
            ),
        );
    }

    private function registerUseCases(): void
    {
        $this->registerGetTasksUseCase();
        $this->registerCompleteTaskUseCase();
    }

    private function registerGetTasksUseCase(): void
    {
        $this->app->bind(
            GetMyClickUpTasksUseCase::class,
            static fn(Application $app): GetMyClickUpTasksUseCase => new GetMyClickUpTasksUseCase(
                repository: $app->make(UserApiKeyRepositoryInterface::class),
                tasksClient: $app->make(TasksClientInterface::class),
                listId: self::requireConfigString(
                    'clickup.list_ids.alz_products_team_tasks',
                    'clickup.list_ids.alz_products_team_tasks must be set in config/clickup.php',
                ),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    private function registerCompleteTaskUseCase(): void
    {
        $this->app->bind(
            CompleteClickUpTaskUseCase::class,
            static fn(Application $app): CompleteClickUpTaskUseCase => new CompleteClickUpTaskUseCase(
                repository: $app->make(UserApiKeyRepositoryInterface::class),
                tasksClient: $app->make(TasksClientInterface::class),
                completeStatus: self::requireConfigString(
                    'clickup.complete_status',
                    'clickup.complete_status (CLICKUP_COMPLETE_STATUS) must be set',
                ),
                logger: $app->make(LoggerInterface::class),
            ),
        );
    }

    private static function configString(string $key, string $default): string
    {
        $value = \config($key, $default);

        return \is_string($value) ? $value : $default;
    }

    private static function configInt(string $key, int $default): int
    {
        $value = \config($key, $default);

        return \is_int($value) ? $value : $default;
    }

    /**
     * Resolve a required string config value or fail fast.
     *
     * @throws InvalidConfigurationException
     */
    private static function requireConfigString(string $key, string $message): string
    {
        $value = \config($key);

        if (!\is_string($value) || $value === '') {
            throw new InvalidConfigurationException($key, $message);
        }

        return $value;
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [
            ApiKeyCipherInterface::class,
            UserApiKeyRepositoryInterface::class,
            ClickUpUserCacheInterface::class,
            ClickUpConfig::class,
            ClickUpHttpTransport::class,
            UsersClientInterface::class,
            TasksClientInterface::class,
            GetMyClickUpTasksUseCase::class,
            CompleteClickUpTaskUseCase::class,
        ];
    }
}
