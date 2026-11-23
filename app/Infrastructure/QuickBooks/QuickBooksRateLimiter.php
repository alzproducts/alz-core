<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\Shared\ValueObjects\Deadline;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * QBO's per-realm limits on Laravel's Redis throttle, one throttle per bucket in turn. A slot an earlier bucket grants
 * while a later one is full stays taken: the over-count only ever errs safe.
 */
final readonly class QuickBooksRateLimiter
{
    public const string CONNECTION = 'quickbooks-limiter';

    /** The longest a call with no deadline waits for room. */
    public const int NO_DEADLINE_WAIT_SECONDS = 10;

    private const string KEY_PREFIX = 'quickbooks-limiter:';

    private const int POLL_MS = 250;

    /**
     * @param non-empty-list<QuickBooksRateLimitBucket> $buckets
     */
    public function __construct(
        private RedisFactory $redis,
        private string $realmId,
        private LoggerInterface $logger,
        private array $buckets,
    ) {}

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
     * @throws ExternalServiceUnavailableException when Redis fails or no slot frees within the fixed wait
     */
    public function acquire(bool $batchCall): void
    {
        try {
            $this->throttle($batchCall, static fn(): int => self::NO_DEADLINE_WAIT_SECONDS);
        } catch (LimiterTimeoutException $e) {
            throw new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, null, $e);
        }
    }

    /**
     * @throws ExternalServiceUnavailableException when Redis fails
     * @throws QuickBooksDeadlineExceededException when no slot frees before `$startBy`
     */
    public function acquireBy(bool $batchCall, Deadline $startBy): void
    {
        try {
            $this->throttle($batchCall, static fn(): int => \max(0, (int) \floor($startBy->secondsLeft(CarbonImmutable::now()))));
        } catch (LimiterTimeoutException $e) {
            throw new QuickBooksDeadlineExceededException($startBy->secondsLeft(CarbonImmutable::now()), $e);
        }
    }

    /**
     * @param Closure(): int $timeoutSeconds read as each bucket starts waiting
     *
     * @throws ExternalServiceUnavailableException
     * @throws LimiterTimeoutException
     */
    private function throttle(bool $batchCall, Closure $timeoutSeconds): void
    {
        try {
            $connection = $this->redis->connection(self::CONNECTION);
            foreach ($this->buckets as $bucket) {
                if ($batchCall || !$bucket->batchOnly) {
                    $connection->throttle(self::KEY_PREFIX . $this->realmId . ':' . $bucket->name)
                        ->allow($bucket->limit)->every($bucket->decaySeconds)->block($timeoutSeconds())->sleep(self::POLL_MS)
                        ->then(static fn(): bool => true);
                }
            }
        } catch (LimiterTimeoutException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->logger->warning('QuickBooks rate limiter unavailable', ['exception' => $e::class]);

            throw new ExternalServiceUnavailableException(QuickBooksOAuthHttpTransport::SERVICE_NAME, null, $e);
        }
    }
}
