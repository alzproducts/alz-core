<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\DateRange;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * Asynchronously synchronize Google Ads spend data to Mixpanel.
 *
 * Queues ad spend synchronization to prevent blocking HTTP responses.
 * Implements exponential backoff retry strategy for rate-limited API calls.
 */
final class SyncGoogleAdsToMixpanelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * Doubled from original 3 to allow middleware-consumed attempts.
     */
    public int $tries = 6;

    /**
     * Maximum number of unhandled exceptions before failing.
     *
     * Matches original $tries — middleware-handled exceptions (release/fail)
     * don't count, only rethrown exceptions decrement this.
     */
    public int $maxExceptions = 3;

    /**
     * Fail the job if it times out.
     */
    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 300;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Job middleware pipeline.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('mixpanel')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     */
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        $useCase->execute(new DateRange($this->from, $this->to));
    }
}
