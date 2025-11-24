<?php

declare(strict_types=1);

namespace App\Presentation\Jobs;

use App\Application\AdSpend\UseCases\SyncCampaignLookupTableUseCase;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\UnexpectedApiResultException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously synchronize campaign lookup table from Google Ads to Mixpanel.
 *
 * Queues the campaign lookup table sync to avoid blocking HTTP requests.
 * Implements exponential backoff for rate limit handling.
 */
final class SyncCampaignLookupTableJob implements ShouldQueue
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

    /**
     * Execute the job: synchronize campaign lookup table.
     *
     * @throws ExternalServiceUnavailableException When external APIs unavailable - will retry
     */
    public function handle(SyncCampaignLookupTableUseCase $useCase): void
    {
        Log::info('Campaign lookup table sync job starting');

        try {
            $useCase->execute();

            Log::info('Campaign lookup table sync job completed successfully');
        } catch (UnexpectedApiResultException $e) {
            // Permanent failure - retrying won't help, needs human investigation
            Log::critical('Unexpected API result during campaign lookup table sync, failing immediately', [
                'service' => $e->serviceName,
                'reason' => $e->reason,
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('External service unavailable during campaign lookup table sync, will retry', [
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
     * Handle job failure with logging.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Campaign lookup table sync job failed', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
