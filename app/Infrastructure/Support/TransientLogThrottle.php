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
     * @param array<string, mixed> $context
     */
    public function logTransient(string $serviceKey, string $message, array $context): void
    {
        try {
            $windowMinutes = $this->resolveThrottleWindow($serviceKey);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: escalate on Redis failure
            $this->logger->warning('TransientLogThrottle cache failure, degrading to escalate', [
                'service' => $serviceKey,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $windowMinutes = self::INITIAL_WINDOW_MINUTES;
        }

        if ($windowMinutes !== null) {
            $this->logger->error($message, [...$context, 'note' => "Subsequent transient failures suppressed for {$windowMinutes} minutes"]);
        } else {
            $this->logger->warning($message, $context);
        }
    }

    /**
     * @return int|null Window minutes if should escalate (first failure), null if throttled
     *
     * @throws InvalidArgumentException
     */
    private function resolveThrottleWindow(string $serviceKey): ?int
    {
        $cacheStore = $this->cache->store();

        $escalationCount = $this->getEscalationCount($cacheStore, $serviceKey);
        $windowMinutes = self::calculateWindowMinutes($escalationCount);

        $throttleKey = self::KEY_PREFIX . $serviceKey;
        $isFirstFailure = $cacheStore->add($throttleKey, true, $windowMinutes * 60);

        if (! $isFirstFailure) {
            return null;
        }

        $this->recordEscalation($cacheStore, $serviceKey, $escalationCount);

        return $windowMinutes;
    }

    /** Exponential backoff: doubles the window with each escalation, capped at MAX_WINDOW_MINUTES. */
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
    private function getEscalationCount(Repository $cacheStore, string $serviceKey): int
    {
        $escalationKey = self::KEY_PREFIX . $serviceKey . ':escalation';
        $escalationCount = $cacheStore->get($escalationKey, 0);

        return \is_int($escalationCount) ? $escalationCount : 0;
    }

    private function recordEscalation(Repository $cacheStore, string $serviceKey, int $escalationCount): void
    {
        $escalationKey = self::KEY_PREFIX . $serviceKey . ':escalation';
        $cacheStore->put($escalationKey, $escalationCount + 1, self::ESCALATION_TTL_MINUTES * 60);
    }
}
