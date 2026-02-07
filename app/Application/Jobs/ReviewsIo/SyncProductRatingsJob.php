<?php

declare(strict_types=1);

namespace App\Application\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\SyncProductRatingsUseCase;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
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
     * @throws TransientApiFailure When Reviews.io API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
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
        } catch (TransientApiFailure $e) {
            Log::warning('Reviews.io ratings sync service unavailable, will retry', [
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
            Log::critical('Reviews.io ratings sync permanent API failure, failing immediately', [
                'exception' => $e::class,
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
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
