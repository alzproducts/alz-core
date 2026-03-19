<?php

declare(strict_types=1);

namespace App\Application\Jobs\Inventory;

use App\Application\Inventory\UseCases\UpdateSkuUseCase;
use App\Application\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asynchronously update a SKU across Linnworks and ShopWired.
 *
 * Uses ShouldBeUnique with a fixed ID to serialize ALL SKU updates.
 * This prevents GetNewItemNumber race conditions where concurrent jobs
 * could receive the same auto-generated sequential SKU.
 *
 * ⚠️ PRODUCTION ONLY: This job modifies LIVE Linnworks and ShopWired data.
 * The audit trail must be in the production database for traceability.
 * See UpdateSkusCommand docblock for details.
 *
 * Exception Strategy:
 * - SkuUpdateFailedException: Fail immediately (compensation failed, manual intervention)
 * - ExternalServiceUnavailableException: Retry with backoff (transient)
 * - Auth/validation errors: Fail immediately (permanent - code/config fix needed)
 *
 * @see UpdateSkuUseCase For orchestration and compensation logic
 */
final class UpdateSkuJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum attempts before permanent failure.
     */
    public int $tries = 3;

    /**
     * Maximum exceptions before permanent failure.
     */
    public int $maxExceptions = 3;

    /**
     * Unique lock duration in seconds.
     *
     * Set to max expected runtime + buffer. Jobs typically complete in <30s,
     * but external APIs may be slow. Lock auto-releases on completion.
     */
    public int $uniqueFor = 300;

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 120;

    /**
     * Backoff delays in seconds.
     *
     * 30s, 2min, 5min: Progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * Get the unique ID for this job.
     *
     * Returns a FIXED ID so all SKU update jobs share one lock.
     * This serializes all SKU updates to prevent race conditions
     * with Linnworks GetNewItemNumber (generates sequential SKUs).
     */
    public function uniqueId(): string
    {
        return 'update-sku';
    }

    public function __construct(
        public readonly UpdateSkuCommand $command,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Execute the job.
     *
     * @throws TransientApiFailure When services unavailable (triggers retry)
     * @throws Throwable On unexpected errors
     */
    public function handle(UpdateSkuUseCase $useCase, LoggerInterface $logger): void
    {
        $logger->info('UpdateSkuJob starting', [
            'old_sku' => $this->command->oldSku,
            'new_sku' => $this->command->newSku?->value,
            'type' => $this->command->type->value,
            'attempt' => $this->attempts(),
        ]);

        try {
            $useCase->execute($this->command);

            $logger->info('UpdateSkuJob completed', ['old_sku' => $this->command->oldSku]);
        } catch (SkuUpdateFailedException $e) {
            // CRITICAL: Compensation failed - systems out of sync, DO NOT retry

            $this->fail($e);

            throw $e;
        } catch (InvalidApiResponseException|AuthenticationExpiredException $e) {
            // Permanent failure - API contract or credentials need fixing

            $this->fail($e);

            throw $e;
        } catch (InvalidApiRequestException|InvalidSkuException|SkuGenerationFailedException|ResourceNotFoundException $e) {
            // Permanent failure - data/request issues that won't resolve on retry

            $this->fail($e);

            throw $e;
        } catch (TransientApiFailure $e) {
            // Dual retry: API-provided delay via release(), or Laravel backoff via rethrow
            $logger->warning('SKU update service unavailable, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempt' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            Log::error('UpdateSkuJob: database failure', [
                'old_sku' => $this->command->oldSku,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        $context = [
            'old_sku' => $this->command->oldSku,
            'exception' => $exception::class,
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof SkuUpdateFailedException) {
            $context['new_sku'] = $exception->newSku;
            $context['failed_system'] = $exception->failedSystem;
        }

        if ($exception instanceof AbstractApiException) {
            Log::error('UpdateSkuJob failed permanently', $context);
        } else {
            Log::critical('UpdateSkuJob failed permanently', $context);
        }
    }
}
