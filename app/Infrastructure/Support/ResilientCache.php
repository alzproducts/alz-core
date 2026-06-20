<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use App\Application\Contracts\ResilientCacheInterface;
use Closure;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class ResilientCache implements ResilientCacheInterface
{
    public function __construct(
        private CacheManager $cache,
        private LoggerInterface $logger,
    ) {}

    public function remember(string $key, int $ttl, Closure $callback, ?string $tag = null): mixed
    {
        $cached = $this->tryGet($key, $tag);

        if ($cached !== null) {
            return $cached;
        }

        $value = $callback();
        $this->tryPut($key, $value, $ttl, $tag);

        return $value;
    }

    public function rememberInt(string $key, int $ttl, Closure $callback, ?string $tag = null): ?int
    {
        $value = $this->remember($key, $ttl, $callback, $tag);

        return ($value === null) ? null : (int) $value;
    }

    public function get(string $key, ?string $tag = null): mixed
    {
        return $this->tryGet($key, $tag);
    }

    public function put(string $key, mixed $value, int $ttl, ?string $tag = null): void
    {
        $this->tryPut($key, $value, $ttl, $tag);
    }

    public function forget(string $key, ?string $tag = null): void
    {
        try {
            $this->cacheStore($tag)->forget($key);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: cache expires naturally
            $this->logFailure('delete', $key, $e, $tag);
        }
    }

    public function flushTag(string $tag): void
    {
        try {
            $this->cache->tags([$tag])->flush();
        } catch (Throwable $e) { // @ignoreException - graceful degradation: tagged entries expire naturally
            $this->logFailure('flush', $tag, $e, $tag);
        }
    }

    private function tryGet(string $key, ?string $tag): mixed
    {
        try {
            return $this->cacheStore($tag)->get($key);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: treat as cache miss
            $this->logFailure('read', $key, $e, $tag);

            return null;
        }
    }

    private function tryPut(string $key, mixed $value, int $ttl, ?string $tag): void
    {
        try {
            $this->cacheStore($tag)->put($key, $value, $ttl);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: return fresh data anyway
            $this->logFailure('write', $key, $e, $tag);
        }
    }

    private function cacheStore(?string $tag): Repository
    {
        return $tag !== null
            ? $this->cache->tags([$tag])
            : $this->cache->store();
    }

    private function logFailure(string $operation, string $key, Throwable $e, ?string $tag): void
    {
        $context = [
            'operation' => $operation,
            'key' => $key,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ];

        if ($tag !== null) {
            $context['tag'] = $tag;
        }

        $this->logger->warning("cache {$operation} failed", $context);
    }
}
