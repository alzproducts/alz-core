<?php

declare(strict_types=1);

namespace App\Application\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\UpdateShopwiredRatingsUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push product ratings from local database to ShopWired custom fields.
 *
 * Stage 2 of the ratings sync pipeline. Reads aggregated ratings from
 * reviews_io.product_ratings and updates ShopWired products.
 */
final class UpdateShopwiredRatingsJob implements ShouldQueue
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
     * @throws DatabaseOperationFailedException When database query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable - will retry
     * @throws AuthenticationExpiredException When credentials invalid (permanent failure)
     * @throws InvalidApiRequestException When request invalid (permanent failure)
     * @throws InvalidApiResponseException When response parsing fails (permanent failure)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(UpdateShopwiredRatingsUseCase $useCase): void
    {
        Log::info('ShopWired ratings update job starting');

        try {
            $result = $useCase->execute();

            Log::info('ShopWired ratings update job completed', [
                'processed' => $result->processed,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ]);
        } catch (InvalidApiResponseException $e) {
            Log::critical('API response validation failed during ShopWired ratings update', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (AuthenticationExpiredException $e) {
            Log::critical('Authentication failed during ShopWired ratings update', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (InvalidApiRequestException $e) {
            Log::critical('Invalid API request during ShopWired ratings update', [
                'service' => $e->serviceName,
                'error' => $e->getMessage(),
                'attempts' => $this->attempts(),
            ]);

            $this->fail($e);
            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            Log::warning('ShopWired API unavailable during ratings update, will retry', [
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
            Log::critical('Unexpected exception in ShopWired ratings update - code update required', [
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
        Log::error('ShopWired ratings update job failed permanently', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
