<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Contracts\Access\AccessTokenVerifierInterface;
use App\Application\Contracts\ResilientCacheInterface;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Infrastructure\Access\SupabaseAuthConfig;
use App\Infrastructure\Access\SupabaseJwksHttpTransport;
use App\Infrastructure\Access\SupabaseJwksKeySet;
use App\Infrastructure\Access\SupabaseJwtVerifier;
use App\Infrastructure\Support\LockableCache;
use App\Infrastructure\Support\TransientLogThrottle;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Provides AuthenticatedUser resolution from request attributes and the access-token verifier.
 *
 * Bridges the middleware layer (which sets authenticated_user in request
 * attributes) with the controller layer (which uses DI for type-safe access).
 *
 * IMPORTANT: Only use in controllers behind auth.supabase middleware.
 * The binding throws if no authenticated user is available.
 */
final class AuthServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->bind(
            AuthenticatedUser::class,
            static fn(Application $app): AuthenticatedUser => self::resolveFromRequest($app),
        );

        $this->app->singleton(
            AccessTokenVerifierInterface::class,
            static fn(Application $app): AccessTokenVerifierInterface => self::buildAccessTokenVerifier($app),
        );
    }

    private static function resolveFromRequest(Application $app): AuthenticatedUser
    {
        $request = $app->make(Request::class);
        $user = $request->attributes->get('authenticated_user');

        if (! $user instanceof AuthenticatedUser) {
            Log::warning('AuthenticatedUser not found in request attributes', [
                'path' => $request->path(),
                'hint' => 'Route may be missing auth.supabase middleware',
            ]);

            throw new LogicException(
                'AuthenticatedUser not available. Ensure route has auth.supabase middleware.',
            );
        }

        return $user;
    }

    private static function supabaseAuthConfig(Application $app): SupabaseAuthConfig
    {
        $url = \config('services.supabase.url');
        if (! \is_string($url) || $url === '') {
            throw new InvalidConfigurationException('services.supabase.url', 'SUPABASE_URL is not set');
        }

        return SupabaseAuthConfig::fromProjectUrl(
            $url,
            allowInsecureHttp: $app->environment('local', 'testing') === true,
        );
    }

    private static function buildAccessTokenVerifier(Application $app): SupabaseJwtVerifier
    {
        $config = self::supabaseAuthConfig($app);
        $cache = $app->make(CacheManager::class);
        $logger = $app->make(LoggerInterface::class);

        return new SupabaseJwtVerifier(
            $config,
            new SupabaseJwksKeySet(
                new SupabaseJwksHttpTransport($config, $app->make(TransientLogThrottle::class)),
                new LockableCache($cache, $logger, SupabaseJwksHttpTransport::SERVICE_NAME),
                $app->make(ResilientCacheInterface::class),
                $cache,
            ),
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [AuthenticatedUser::class, AccessTokenVerifierInterface::class];
    }
}
