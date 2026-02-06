<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Mixpanel;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\PayloadSerializationException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    use SerializesModels;

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
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When external APIs unavailable - will retry
     * @throws PayloadSerializationException When data serialization fails (permanent failure)
     * @throws AuthenticationExpiredException When API credentials invalid (permanent failure)
     * @throws Throwable When unexpected errors occur - indicates code update required
     */
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        $dateRange = new DateRange($this->from, $this->to);
        $fromString = $this->from->format('Y-m-d');
        $toString = $this->to->format('Y-m-d');

        Log::info('Queued Bing Ads to Mixpanel sync starting', [
            'from' => $fromString,
            'to' => $toString,
        ]);

        try {
            $useCase->execute($dateRange);

            Log::info('Queued Bing Ads to Mixpanel sync completed', [
                'from' => $fromString,
                'to' => $toString,
            ]);
        } catch (PayloadSerializationException $e) {
            // Permanent failure - data integrity issue, retrying won't help
            Log::critical('Payload serialization failed during Bing Ads sync, failing immediately', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need fixing, don't waste retries
            Log::critical('Authentication failed during Bing Ads sync, failing immediately', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('External service unavailable during Bing Ads sync, will retry', [
                'from' => $fromString,
                'to' => $toString,
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter ?? 'using backoff',
                'attempts' => $this->attempts(),
            ]);

            // Use API's retry delay if provided, otherwise let Laravel use backoff array
            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            // Fail immediately - don't waste retries on unknown errors
            Log::critical('Unexpected exception in Bing Ads sync - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'from' => $fromString,
                'to' => $toString,
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Bing Ads to Mixpanel sync job failed', [
            'from' => $this->from->format('Y-m-d'),
            'to' => $this->to->format('Y-m-d'),
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
