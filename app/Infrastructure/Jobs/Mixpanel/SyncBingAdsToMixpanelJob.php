<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronously synchronize Bing Ads spend data to Mixpanel.
 *
 * Queues ad spend synchronization to prevent blocking HTTP responses.
 * Implements exponential backoff retry strategy for rate-limited API calls.
 *
 * Key difference from Google Ads: Bing Ads async reporting can take longer,
 * so this job has extended timeout to accommodate report generation polling.
 */
final class SyncBingAdsToMixpanelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     *
     * 3 attempts: quick retries for transient issues + 1hr fallback for longer outages.
     */
    public int $tries = 3;

    /**
     * Job timeout in seconds.
     *
     * Extended for Bing Ads async reporting (submit → poll → download).
     */
    public int $timeout = 600;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600];

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When Bing Ads API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncAdSpendUseCase $useCase, LoggerInterface $logger): void
    {
        $dateRange = new DateRange($this->from, $this->to);
        $fromString = $this->from->format('Y-m-d');
        $toString = $this->to->format('Y-m-d');

        $logger->info('Queued Bing Ads to Mixpanel sync starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $useCase->execute($dateRange);

            $logger->info('Queued Bing Ads to Mixpanel sync completed', [
                'from' => $fromString,
                'to' => $toString,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('Bing Ads sync service unavailable, will retry', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (PermanentApiFailure $e) {
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Bing Ads to Mixpanel sync job failed', $context);
        } else {
            Log::critical('Bing Ads to Mixpanel sync job failed', $context);
        }
    }
}
