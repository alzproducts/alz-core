<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp\Cache;

use App\Application\ClickUp\DTOs\ClickUpTaskDataDTO;
use App\Application\ClickUp\Queries\ClickUpTaskQueryParams;
use App\Application\Contracts\ClickUp\ClickUpTasksCacheInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\Guid;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use Psr\SimpleCache\InvalidArgumentException;

/**
 * Caches ClickUp task lists per user + query params.
 *
 * Key: `clickup:tasks:{userId}:{statusesHash}:{tagsHash}` — TTL: 120 seconds.
 * Arrays are sorted before hashing so equivalent queries hit the same cache entry.
 */
final readonly class ClickUpTasksCache implements ClickUpTasksCacheInterface
{
    private const string KEY_PREFIX = 'clickup:tasks:';

    private const int TTL_SECONDS = 120;

    public function __construct(
        private CacheRepository $cache,
    ) {}

    /**
     * @return list<ClickUpTaskDataDTO>|null
     *
     * @throws ExternalServiceUnavailableException
     */
    public function get(Guid $userId, ClickUpTaskQueryParams $params): ?array
    {
        $key = $this->key($userId, $params);

        try {
            /** @var list<array<string, mixed>>|null $raw */
            $raw = $this->cache->get($key);
        } catch (InvalidArgumentException $e) {
            Log::error('ClickUp tasks cache read failed', ['error' => $e->getMessage()]);
            throw new ExternalServiceUnavailableException('cache', null, $e);
        }

        if (!\is_array($raw)) {
            return null;
        }

        return self::hydrateItems($raw);
    }

    /**
     * @param list<ClickUpTaskDataDTO> $tasks
     */
    public function put(Guid $userId, ClickUpTaskQueryParams $params, array $tasks): void
    {
        $this->cache->put(
            $this->key($userId, $params),
            \array_map(static fn(ClickUpTaskDataDTO $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'status' => $t->status,
                'dueDate' => $t->dueDate,
                'tags' => $t->tags,
                'url' => $t->url,
            ], $tasks),
            self::TTL_SECONDS,
        );
    }

    public function forget(Guid $userId): void
    {
        // Full prefix-based invalidation requires a tagged cache driver.
        // The 120s TTL is the safety net; callers treat this as best-effort.
    }

    /**
     * @param list<array<string, mixed>> $raw
     *
     * @return list<ClickUpTaskDataDTO>
     */
    private static function hydrateItems(array $raw): array
    {
        $items = [];
        foreach ($raw as $item) {
            $dto = self::hydrateItem($item);
            if ($dto !== null) {
                $items[] = $dto;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function hydrateItem(array $item): ?ClickUpTaskDataDTO
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $status = $item['status'] ?? null;
        if (!\is_string($id) || !\is_string($name) || !\is_string($status)) {
            return null;
        }

        /** @var list<string> $tags */
        $tags = \is_array($item['tags'] ?? null) ? $item['tags'] : [];

        return new ClickUpTaskDataDTO(
            id: $id,
            name: $name,
            status: $status,
            dueDate: \is_string($item['dueDate'] ?? null) ? $item['dueDate'] : null,
            tags: $tags,
            url: \is_string($item['url'] ?? null) ? $item['url'] : null,
        );
    }

    private function key(Guid $userId, ClickUpTaskQueryParams $params): string
    {
        $statuses = $params->statuses;
        $tags = $params->tags;
        \sort($statuses);
        \sort($tags);

        $statusesHash = \hash('sha256', \implode(',', $statuses));
        $tagsHash = \hash('sha256', \implode(',', $tags));

        return self::KEY_PREFIX . $userId->value . ':' . $statusesHash . ':' . $tagsHash;
    }
}
