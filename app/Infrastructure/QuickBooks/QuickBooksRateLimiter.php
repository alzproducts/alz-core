<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * QBO's per-realm limits, shared by every process through one Redis script over sliding logs timed by Redis `TIME`.
 * Fails closed: when Redis errors or replies oddly, no call is sent (an open failure would break the caps across processes).
 */
final readonly class QuickBooksRateLimiter
{
    public const string CONNECTION = 'quickbooks-limiter';

    private const string KEY_PREFIX = 'quickbooks-limiter:';

    /**
     * Returns 0 and records a grant in every bucket, or the milliseconds until every bucket has room, recording nothing.
     * KEYS: one per bucket. ARGV: window ms and limit per bucket, then a unique grant id.
     */
    private const string SCRIPT = <<<'LUA'
        if redis.replicate_commands then redis.replicate_commands() end
        local t = redis.call('TIME')
        local now = tonumber(t[1]) * 1000 + math.floor(tonumber(t[2]) / 1000)
        local wait = 0
        for i = 1, #KEYS do
          local window = tonumber(ARGV[i * 2 - 1])
          local limit = tonumber(ARGV[i * 2])
          redis.call('ZREMRANGEBYSCORE', KEYS[i], '-inf', now - window)
          local count = redis.call('ZCARD', KEYS[i])
          if count >= limit then
            local oldest = redis.call('ZRANGE', KEYS[i], count - limit, count - limit, 'WITHSCORES')
            local free = tonumber(oldest[2]) + window - now
            if free > wait then wait = free end
          end
        end
        if wait > 0 then return wait end
        local member = now .. ':' .. ARGV[#KEYS * 2 + 1]
        for i = 1, #KEYS do
          redis.call('ZADD', KEYS[i], now, member)
          redis.call('PEXPIRE', KEYS[i], tonumber(ARGV[i * 2 - 1]))
        end
        return 0
        LUA;

    /**
     * @param non-empty-list<QuickBooksRateLimitBucket> $buckets
     */
    public function __construct(
        private RedisFactory $redis,
        private string $realmId,
        private LoggerInterface $logger,
        private array $buckets,
    ) {
        Assert::notEmpty($realmId);
    }

    /**
     * QBO allows 10 calls a second and 500 a minute per realm, and 40 batch calls a minute.
     *
     * @return non-empty-list<QuickBooksRateLimitBucket>
     */
    public static function defaultBuckets(): array
    {
        return [
            new QuickBooksRateLimitBucket('second', 1_000, 10, false),
            new QuickBooksRateLimitBucket('minute', 60_000, 500, false),
            new QuickBooksRateLimitBucket('batch-minute', 60_000, 40, true),
        ];
    }

    /**
     * Waits for a grant in every bucket the call counts against.
     *
     * @throws ExternalServiceUnavailableException  when Redis errors or replies with anything but a wait time
     * @throws QuickBooksDeadlineExceededException when the grant would come after `$startBy`; nothing is recorded
     */
    public function acquire(bool $batchCall, Deadline $startBy): void
    {
        $buckets = \array_values(\array_filter(
            $this->buckets,
            static fn(QuickBooksRateLimitBucket $bucket): bool => $batchCall || !$bucket->batchOnly,
        ));

        for ($waitMs = $this->tryGrant($buckets); $waitMs > 0; $waitMs = $this->tryGrant($buckets)) {
            $secondsLeft = $startBy->secondsLeft(CarbonImmutable::now());
            if ($waitMs / 1000 > $secondsLeft) {
                throw new QuickBooksDeadlineExceededException($secondsLeft);
            }

            Sleep::for($waitMs)->milliseconds();
        }
    }

    /**
     * @param list<QuickBooksRateLimitBucket> $buckets
     *
     * @return int<0, max> milliseconds to wait; 0 once granted
     *
     * @throws ExternalServiceUnavailableException
     */
    private function tryGrant(array $buckets): int
    {
        try {
            // intentional: phpredis argument order (script, arguments, key count); the configured client is phpredis
            $reply = $this->redis->connection(self::CONNECTION)->command('eval', [self::SCRIPT, $this->scriptArguments($buckets), \count($buckets)]);
        } catch (Throwable $e) {
            $this->logger->warning('QuickBooks rate limiter unavailable', ['exception' => $e::class]);

            throw new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, null, $e);
        }

        // intentional: phpredis answers a script error with false rather than throwing; reading that as a grant fails open
        if (!\is_int($reply) || $reply < 0) {
            $this->logger->warning('QuickBooks rate limiter replied with a non-integer', ['reply_type' => \get_debug_type($reply)]);

            throw new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME);
        }

        return $reply;
    }

    /**
     * @param list<QuickBooksRateLimitBucket> $buckets
     *
     * @return list<int|string> the bucket keys, each bucket's window and limit, then a unique grant id
     */
    private function scriptArguments(array $buckets): array
    {
        $arguments = \array_map(fn(QuickBooksRateLimitBucket $bucket): string => self::KEY_PREFIX . $this->realmId . ':' . $bucket->name, $buckets);
        foreach ($buckets as $bucket) {
            $arguments[] = $bucket->windowMs;
            $arguments[] = $bucket->limit;
        }

        $arguments[] = Str::uuid()->toString();

        return $arguments;
    }
}
