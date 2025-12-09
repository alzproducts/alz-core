<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\AdSpend\UseCases\SyncAdSpendUseCase;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\PayloadSerializationException;
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

    private readonly ?string $date;

    public function __construct(?string $date = null)
    {
        // Store the date parameter if provided (for manual testing with specific dates)
        $this->date = $date;
    }

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When external APIs unavailable - will retry
     */
    public function handle(SyncAdSpendUseCase $useCase): void
    {
        // Calculate the date at execution time, not at job instantiation.
        // This ensures the job works correctly with both schedule:run (cron-based)
        // and schedule:work (long-running daemon like Octane).
        $dateToSync = $this->date ?? \now()->subDay()->format('Y-m-d');

        Log::info('Queued Google Ads to Mixpanel sync starting', ['date' => $dateToSync]);

        try {
            $useCase->execute($dateToSync);

            Log::info('Queued Google Ads to Mixpanel sync completed', ['date' => $dateToSync]);
        } catch (PayloadSerializationException $e) {
            // Permanent failure - data integrity issue, retrying won't help
            Log::critical('Payload serialization failed during sync, failing immediately', [
                'date' => $dateToSync,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
        } catch (AuthenticationExpiredException $e) {
            // Permanent failure - credentials need fixing, don't waste retries
            Log::critical('Authentication failed during sync, failing immediately', [
                'date' => $dateToSync,
                'service' => $e->serviceName,
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('External service unavailable during sync, will retry', [
                'date' => $dateToSync,
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
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Google Ads to Mixpanel sync job failed', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
