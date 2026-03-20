<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\ReviewsIo;

use App\Application\ReviewsIo\UseCases\UpdateShopwiredRatingsUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Enums\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Push product ratings from local database to ShopWired custom fields.
 *
 * Stage 2 of the ratings sync pipeline. Reads aggregated ratings from
 * reviews_io.product_ratings and updates ShopWired products.
 */
final class UpdateShopwiredRatingsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;
    public int $timeout = 900;
    public int $uniqueFor = 1200;

    /** @var array<int> */
    public array $backoff = [30, 60, 120, 240];

    public function uniqueId(): string
    {
        return 'update-shopwired-ratings';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @throws DatabaseOperationFailedException When database query fails
     * @throws DuplicateRecordException On constraint violation
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur
     */
    public function handle(UpdateShopwiredRatingsUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('ShopWired ratings update job starting');

        try {
            $result = $useCase->execute();

            $logger->info('ShopWired ratings update job completed', [
                'processed' => $result->processed,
                'updated' => $result->updated,
                'skipped' => $result->skipped,
                'failed' => $result->failed,
            ]);
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('ShopWired ratings update service unavailable, will retry', [
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

    public function failed(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired ratings update job failed permanently', $context);
        } else {
            Log::critical('ShopWired ratings update job failed permanently', $context);
        }
    }
}
