<?php

declare(strict_types=1);

namespace App\Infrastructure\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;
use Throwable;

final readonly class TransientLogThrottle
{
    private const int INITIAL_WINDOW_MINUTES = 5;

    private const int MAX_WINDOW_MINUTES = 30;

    private const int ESCALATION_TTL_MINUTES = 60;

    private const string KEY_PREFIX = 'transient_log_throttle:';

    public function __construct(
        private CacheManager $cache,
        private LoggerInterface $logger,
    ) {}

    /**
     * Check whether a transient failure for the given service should escalate to error level.
     *
     * @return int|null Window minutes if should escalate (log at error), null if throttled (log at warning)
     */
    public function check(string $serviceKey): ?int
    {
        try {
            return $this->doCheck($serviceKey);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: escalate on Redis failure
            $this->logger->warning('TransientLogThrottle cache failure, degrading to escalate', [
                'service' => $serviceKey,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return self::INITIAL_WINDOW_MINUTES;
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    private function doCheck(string $serviceKey): ?int
    {
        $store = $this->cache->store();

        $escalationCount = $this->getEscalationCount($store, $serviceKey);
        $windowMinutes = self::calculateWindowMinutes($escalationCount);

        $throttleKey = self::KEY_PREFIX . $serviceKey;
        $isFirstFailure = $store->add($throttleKey, true, $windowMinutes * 60);

        if (! $isFirstFailure) {
            return null;
        }

        $escalationKey = self::KEY_PREFIX . $serviceKey . ':escalation';
        $store->put($escalationKey, $escalationCount + 1, self::ESCALATION_TTL_MINUTES * 60);

        return $windowMinutes;
    }

    private static function calculateWindowMinutes(int $escalationCount): int
    {
        return (int) \min(
            self::INITIAL_WINDOW_MINUTES * (2 ** $escalationCount),
            self::MAX_WINDOW_MINUTES,
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function getEscalationCount(Repository $store, string $serviceKey): int
    {
        $escalationKey = self::KEY_PREFIX . $serviceKey . ':escalation';
        $value = $store->get($escalationKey, 0);

        return \is_int($value) ? $value : 0;
    }
}
