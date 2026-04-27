<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Cache;

use App\Application\ClickUp\DTOs\ClickUpUserDataDTO;
use App\Application\Contracts\ClickUp\ClickUpUserCacheInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\Guid;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Caches ClickUp user identity (ID + email) per Supabase user UUID.
 *
 * Key: `clickup:user:{supabaseUuid}` — TTL: 24 hours.
 * Stores JSON `{"id":"...","email":"..."}` so both pieces are available
 * without a second cache key or an extra ClickUp API call.
 */
final readonly class ClickUpUserCache implements ClickUpUserCacheInterface
{
    private const string KEY_PREFIX = 'clickup:user:';

    private const int TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private CacheRepository $cache,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function get(Guid $userId): ?ClickUpUserDataDTO
    {
        $data = $this->readFromCache($userId);

        if (!\is_array($data)) {
            return null;
        }

        return self::hydrateFromArray($data);
    }

    public function put(Guid $userId, ClickUpUserDataDTO $data): void
    {
        $this->cache->put(
            $this->key($userId),
            ['id' => $data->id, 'email' => $data->email],
            self::TTL_SECONDS,
        );
    }

    public function forget(Guid $userId): void
    {
        $this->cache->forget($this->key($userId));
    }

    /**
     * @return array<string, string>|null
     *
     * @throws ExternalServiceUnavailableException
     */
    private function readFromCache(Guid $userId): ?array
    {
        try {
            /** @var array<string, string>|null $data */
            $data = $this->cache->get($this->key($userId));

            return $data;
        } catch (InvalidArgumentException $e) {
            Log::error('ClickUp user cache read failed', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailableException('cache', null, $e);
        }
    }

    /**
     * @param array<string, string> $data
     */
    private static function hydrateFromArray(array $data): ?ClickUpUserDataDTO
    {
        $id = $data['id'] ?? null;
        $email = $data['email'] ?? null;

        if (!\is_string($id) || !\is_string($email)) {
            return null;
        }

        return new ClickUpUserDataDTO(id: $id, email: $email);
    }

    private function key(Guid $userId): string
    {
        return self::KEY_PREFIX . $userId->value;
    }
}
