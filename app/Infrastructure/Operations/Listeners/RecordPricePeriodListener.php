<?php

declare(strict_types=1);

namespace App\Infrastructure\Operations\Listeners;

use App\Application\Operations\UseCases\RecordPricePeriodUseCase;
use App\Domain\Catalog\Product\Events\SkuRetailPricingUpdatedEvent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records SCD2 price period when a SKU's retail pricing changes.
 *
 * Queued — runs independently of the UseCase that dispatched the event.
 * Follows the same exception handling pattern as Jobs: explicit try/catch
 * with transient retry, permanent fail, and unexpected error escalation.
 *
 * Thin entry point (like a controller): extracts event data and delegates
 * to RecordPricePeriodUseCase for business logic.
 */
final class RecordPricePeriodListener implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 4;

    /** @var list<int> 1min, 5min, 20min */
    public array $backoff = [60, 300, 1200];

    public function __construct(
        private readonly RecordPricePeriodUseCase $useCase,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException On transient database failure (triggers retry)
     * @throws DatabaseOperationFailedException On permanent database failure (fails immediately)
     * @throws DuplicateRecordException On unique constraint violation (fails immediately)
     * @throws Throwable On unexpected error — indicates a code issue
     */
    public function handle(SkuRetailPricingUpdatedEvent $event): void
    {
        try {
            $this->useCase->execute($event->sku, $event->newPrices);
        } catch (ExternalServiceUnavailableException $e) {
            $this->handleTransientFailure($e, $event);
        } catch (DatabaseOperationFailedException|DuplicateRecordException $e) {
            $this->failWithLog('Price period recording: permanent database failure', 'error', $e, $event);
        } catch (Throwable $e) {
            Log::critical('Price period recording: unexpected error', [
                'sku' => $event->sku->value,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->fail($e);

            throw $e;
        }
    }

    /**
     * Release with API-provided delay, or rethrow for standard backoff.
     *
     * @throws ExternalServiceUnavailableException When no retryAfter provided
     */
    private function handleTransientFailure(
        ExternalServiceUnavailableException $e,
        SkuRetailPricingUpdatedEvent $event,
    ): void {
        Log::warning('Price period recording: transient database failure, will retry', [
            'sku' => $event->sku->value,
            'service' => $e->serviceName,
            'retry_after' => $e->retryAfter,
            'attempts' => $this->attempts(),
        ]);

        if ($e->retryAfter !== null) {
            $this->release($e->retryAfter);
        } else {
            throw $e;
        }
    }

    /**
     * Log, mark as failed, and rethrow.
     *
     * @throws Throwable Always rethrown
     */
    private function failWithLog(
        string $message,
        string $level,
        Throwable $e,
        SkuRetailPricingUpdatedEvent $event,
    ): never {
        Log::log($level, $message, [
            'sku' => $event->sku->value,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
        $this->fail($e);

        throw $e;
    }

    /**
     * Handle listener failure after all retries exhausted.
     */
    public function failed(SkuRetailPricingUpdatedEvent $event, Throwable $exception): void
    {
        Log::error('Price period recording failed permanently', [
            'sku' => $event->sku->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
