<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Support\Sleep;
use Psr\Log\LoggerInterface;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * QBO's per-realm limits, shared by every process through Laravel's Redis duration limiter (the one `Redis::throttle()`
 * builds), one fixed window per bucket. A burst across a window edge can still draw a 429, which the transport retries.
 * Fails closed: when Redis errors or replies oddly, no call is sent (an open failure would break the caps across processes).
 */
final readonly class QuickBooksRateLimiter
{
    public const string CONNECTION = 'quickbooks-limiter';

    /** The longest a call with no deadline waits for room: one full minute window. */
    public const int NO_DEADLINE_WAIT_SECONDS = 60;

    private const string KEY_PREFIX = 'quickbooks-limiter:';

    private const int MIN_WAIT_MS = 50;

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
            new QuickBooksRateLimitBucket('second', 1, 10, false),
            new QuickBooksRateLimitBucket('minute', 60, 500, false),
            new QuickBooksRateLimitBucket('batch-minute', 60, 40, true),
        ];
    }

    /**
     * Waits for a slot in every bucket the call counts against, checking every bucket before taking any slot.
     *
     * @throws ExternalServiceUnavailableException  when Redis errors or replies oddly
     * @throws QuickBooksDeadlineExceededException when room would come after `$startBy`; nothing is recorded
     */
    public function acquire(bool $batchCall, ?Deadline $startBy = null): void
    {
        $startBy ??= Deadline::after(CarbonImmutable::now(), self::NO_DEADLINE_WAIT_SECONDS);
        $limiters = $this->limiters($batchCall);

        while (true) {
            $waitSeconds = $this->secondsUntilRoom($limiters);
            // intentional: a slot lost to another process mid-grant stays taken; the over-count only ever errs safe
            if ($waitSeconds <= 0.0 && $this->grant($limiters)) {
                return;
            }

            self::waitUnlessPast($startBy, $waitSeconds);
        }
    }

    /**
     * @throws QuickBooksDeadlineExceededException when the wait would end after `$startBy`
     */
    private static function waitUnlessPast(Deadline $startBy, float $waitSeconds): void
    {
        $secondsLeft = $startBy->secondsLeft(CarbonImmutable::now());
        if ($waitSeconds > $secondsLeft) {
            throw new QuickBooksDeadlineExceededException($secondsLeft);
        }

        if ($waitSeconds > 0.0) {
            Sleep::for((int) \ceil($waitSeconds * 1000))->milliseconds();
        }
    }

    /**
     * @return non-empty-list<DurationLimiter>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function limiters(bool $batchCall): array
    {
        try {
            $connection = $this->redis->connection(self::CONNECTION);
        } catch (Throwable $e) {
            throw $this->unavailable($e);
        }

        $limiters = [];
        foreach ($this->buckets as $bucket) {
            if ($batchCall || !$bucket->batchOnly) {
                $limiters[] = new DurationLimiter($connection, self::KEY_PREFIX . $this->realmId . ':' . $bucket->name, $bucket->limit, $bucket->decaySeconds);
            }
        }

        Assert::notEmpty($limiters);

        return $limiters;
    }

    /**
     * Reads every bucket without taking a slot.
     *
     * @param non-empty-list<DurationLimiter> $limiters
     *
     * @return float seconds until the fullest bucket's window ends; 0 when all have room
     *
     * @throws ExternalServiceUnavailableException
     */
    private function secondsUntilRoom(array $limiters): float
    {
        $wait = 0.0;
        foreach ($limiters as $limiter) {
            try {
                $full = $limiter->tooManyAttempts();
            } catch (Throwable $e) {
                throw $this->unavailable($e);
            }

            $windowEnd = $this->windowEnd($limiter->decaysAt);
            if ($full) {
                $wait = \max($wait, self::MIN_WAIT_MS / 1000, $windowEnd - CarbonImmutable::now()->getPreciseTimestamp(3) / 1000);
            }
        }

        return $wait;
    }

    /**
     * @param non-empty-list<DurationLimiter> $limiters
     *
     * @return bool false when another process took the last slot of a bucket first
     *
     * @throws ExternalServiceUnavailableException
     */
    private function grant(array $limiters): bool
    {
        foreach ($limiters as $limiter) {
            try {
                $granted = $limiter->acquire();
            } catch (Throwable $e) {
                throw $this->unavailable($e);
            }

            $this->windowEnd($limiter->decaysAt);
            if (!$granted) {
                return false;
            }
        }

        return true;
    }

    /**
     * phpredis answers a script error with false rather than throwing; the duration limiter then reads null as "no
     * room" or "denied", never a grant, so the window end is what tells an odd reply apart.
     *
     * @throws ExternalServiceUnavailableException
     */
    private function windowEnd(mixed $decaysAt): float
    {
        if (\is_int($decaysAt) || (\is_string($decaysAt) && \ctype_digit($decaysAt))) {
            return (float) $decaysAt;
        }

        $this->logger->warning('QuickBooks rate limiter replied without a window end', ['reply_type' => \get_debug_type($decaysAt)]);

        throw new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME);
    }

    private function unavailable(Throwable $e): ExternalServiceUnavailableException
    {
        $this->logger->warning('QuickBooks rate limiter unavailable', ['exception' => $e::class]);

        return new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, null, $e);
    }
}
