<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\SyncProductRatingsUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sync product ratings from Reviews.io API to local database.
 *
 * Stage 1 of the ratings sync pipeline. Fetches ratings for all SKUs
 * and stores them in reviews_io.product_ratings.
 */
final class SyncProductRatingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;
    public int $timeout = 900;

    /** @var array<int> */
    public array $backoff = [30, 60, 120, 240];

    public function __construct()
    {
        $this->onQueue('low');
    }

    /**
     * @throws ExternalServiceUnavailableException When Reviews.io API unavailable - will retry
     * @throws InvalidApiResponseException When API contract violation (permanent failure)
     * @throws AuthenticationExpiredException When credentials invalid (permanent failure)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(SyncProductRatingsUseCase $useCase): void
    {
        Log::info('Reviews.io ratings sync job starting');

        try {
            $result = $useCase->execute();

            Log::info('Reviews.io ratings sync job completed', [
                'fetched' => $result->fetched,
                'saved' => $result->saved,
                'failed' => $result->failed,
            ]);
        } catch (InvalidApiResponseException $e) {
            Log::critical('API response validation failed during Reviews.io ratings sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            Log::critical('Authentication failed during Reviews.io ratings sync', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('Reviews.io API unavailable during ratings sync, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter ?? 'using backoff',
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            Log::critical('Unexpected exception in Reviews.io ratings sync - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Reviews.io ratings sync job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
