<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\AdSpend\Exceptions\ApiRateLimitException;
use App\Domain\AdSpend\Exceptions\GoogleAdsApiException;
use App\Domain\AdSpend\Exceptions\MixpanelApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
    use SerializesModels;

    /**
     * Maximum number of attempts before giving up.
     * Public and mutable per Laravel ShouldQueue contract.
     */
    public int $tries = 5;

    /**
     * Seconds to wait before retrying (exponential backoff: 60, 120, 240, 480, 960).
     * Public and mutable per Laravel ShouldQueue contract.
     *
     * @var array<int>
     */
    public array $backoff = [60, 120, 240, 480, 960];

    public function __construct(
        private readonly string $date,
    ) {}

    /**
     * Execute the job.
     *
     * @throws ApiRateLimitException Rate limit hit - will retry with backoff
     * @throws GoogleAdsApiException API error - will retry
     * @throws MixpanelApiException Mixpanel error - will retry
     */
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        Log::info('Queued Google Ads to Mixpanel sync starting', ['date' => $this->date]);

        try {
            $useCase->execute($this->date);

            Log::info('Queued Google Ads to Mixpanel sync completed', ['date' => $this->date]);
        } catch (ApiRateLimitException $e) {
            Log::warning('Rate limited during sync, will retry', [
                'date' => $this->date,
                'retry_after' => $e->getRetryAfter(),
                'attempts' => $this->attempts(),
            ]);

            // Release back to queue with exponential backoff
            $this->release($this->backoff[$this->attempts() - 1] ?? 960);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Google Ads to Mixpanel sync job failed', [
            'date' => $this->date,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
