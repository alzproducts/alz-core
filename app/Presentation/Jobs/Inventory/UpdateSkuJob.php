<?php

declare(strict_types=1);

namespace App\Presentation\Jobs\Inventory;

use App\Application\Inventory\UseCases\UpdateSkuUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Inventory\SkuGenerationFailedException;
use App\Domain\Exceptions\Inventory\SkuUpdateFailedException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asynchronously update a SKU across Linnworks and ShopWired.
 *
 * Uses ShouldBeUnique with a fixed ID to serialize ALL SKU updates.
 * This prevents GetNewItemNumber race conditions where concurrent jobs
 * could receive the same auto-generated sequential SKU.
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
    use SerializesModels;

    /**
     * Maximum attempts before permanent failure.
     */
    public int $tries = 3;

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
    ) {}

    /**
     * Execute the job.
     *
     * @throws ExternalServiceUnavailableException When services unavailable (triggers retry)
     * @throws Throwable On unexpected errors
     */
    public function handle(UpdateSkuUseCase $useCase): void
    {
        Log::info('UpdateSkuJob starting', [
            'old_sku' => $this->command->oldSku,
            'new_sku' => $this->command->newSku?->value,
            'type' => $this->command->type->value,
            'attempt' => $this->attempts(),
        ]);

        try {
            $useCase->execute($this->command);

            Log::info('UpdateSkuJob completed', ['old_sku' => $this->command->oldSku]);
        } catch (SkuUpdateFailedException $e) {
            // CRITICAL: Compensation failed - systems out of sync, DO NOT retry
            Log::critical('SKU update compensation failed - manual intervention required', [
                'old_sku' => $e->oldSku,
                'new_sku' => $e->newSku,
                'failed_system' => $e->failedSystem,
            ]);

            $this->fail($e);

            throw $e;
        } catch (InvalidApiResponseException|AuthenticationExpiredException $e) {
            // Permanent failure - API contract or credentials need fixing
            Log::critical('SKU update failed with permanent error', [
                'exception' => $e::class,
                'service' => $e->serviceName,
                'attempt' => $this->attempts(),
            ]);

            $this->fail($e);

            throw $e;
        } catch (InvalidApiRequestException|InvalidSkuException|SkuGenerationFailedException|ResourceNotFoundException $e) {
            // Permanent failure - data/request issues that won't resolve on retry
            Log::error('SKU update failed with permanent error', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'old_sku' => $this->command->oldSku,
            ]);

            $this->fail($e);

            throw $e;
        } catch (ExternalServiceUnavailableException $e) {
            // Transient failure - retry with API's delay or use backoff
            Log::warning('Service unavailable during SKU update', [
                'service' => $e->serviceName,
                'attempt' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            // Unexpected exception = code needs updating
            Log::critical('Unexpected exception in UpdateSkuJob - code update required', [
                'job' => self::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('UpdateSkuJob exhausted all retries', [
            'old_sku' => $this->command->oldSku,
            'exception' => $exception::class,
            'attempts' => $this->attempts(),
        ]);
    }
}
