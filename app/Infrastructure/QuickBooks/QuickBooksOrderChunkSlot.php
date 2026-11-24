<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The slots every chunk job shares, whatever started it.
 */
final readonly class QuickBooksOrderChunkSlot
{
    public const string NAME = 'quickbooks-order-sync-chunk';

    /** Outlives the job's timeout, so a killed worker's slot frees only once none of its calls can still run. */
    public const int HOLD_SECONDS = 960;

    private const string SERVICE_NAME = 'Redis';

    public function __construct(
        private RedisFactory $redis,
        private LoggerInterface $logger,
        private int $concurrency,
    ) {}

    /**
     * Runs the work in a slot and throws what it throws; false when no slot was free.
     *
     * @param-immediately-invoked-callable $work
     *
     * @param Closure(): void $work
     *
     * @throws ExternalServiceUnavailableException when the slot cannot be asked for
     * @throws Throwable
     */
    public function run(Closure $work): bool
    {
        $attempt = new QuickBooksOrderChunkSlotAttempt();

        try {
            $this->inSlot(static function () use ($attempt, $work): void {
                $attempt->run($work);
            });
        } catch (Throwable $e) {
            if (!$attempt->ran && $e instanceof LimiterTimeoutException) {
                return false;
            }
            $outcome = $this->outcome($attempt, $e);
            if ($outcome !== null) {
                throw $outcome;
            }
        }

        return true;
    }

    /**
     * @param-immediately-invoked-callable $work
     *
     * @param Closure(): void $work
     *
     * @throws Throwable
     */
    private function inSlot(Closure $work): void
    {
        $this->redis->connection(QuickBooksRateLimiter::CONNECTION)->funnel(self::NAME)
            ->limit($this->concurrency)->releaseAfter(self::HOLD_SECONDS)->block(0)
            ->then($work);
    }

    /**
     * The work's outcome stands when releasing its slot fails.
     */
    private function outcome(QuickBooksOrderChunkSlotAttempt $attempt, Throwable $thrown): ?Throwable
    {
        if (!$attempt->ran) {
            $this->logger->warning('QuickBooks order chunk slot unavailable', ['exception' => $thrown::class]);

            return new ExternalServiceUnavailableException(self::SERVICE_NAME, null, $thrown);
        }
        if ($thrown !== $attempt->failure) {
            $this->logger->warning('QuickBooks order chunk slot not released', ['exception' => $thrown::class]);
        }

        return $attempt->failure;
    }
}
