<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Conversion;

use App\Application\Conversion\UseCases\HandleLeadConversionFailureUseCase;
use App\Application\Conversion\UseCases\ProcessLeadConversionUseCase;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Uploads a lead conversion to Google Ads asynchronously.
 *
 * Uses ShouldBeUnique by submission ID to prevent duplicate uploads if the job
 * is retried while another instance is still processing.
 *
 * Exception Strategy:
 * - TransientApiFailure: Handled by {@see HandleApiExceptions} middleware (release/rethrow)
 * - PermanentApiFailure: Handled by {@see HandleApiExceptions} middleware (fail immediately)
 * - Data exceptions: Caught in handle() → fail immediately (non-API permanent failures)
 * - Unexpected Throwable: Retried by Laravel, failed() on exhaustion
 */
final class ProcessLeadConversionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum attempts before permanent failure.
     * 5 attempts = initial + 4 retries (using all 4 backoff delays).
     */
    public int $tries = 5;

    public int $maxExceptions = 5;

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr, 12hr: progressive delays for transient failures.
     *
     * @var array<int>
     */
    public array $backoff = [60, 300, 3600, 43200];

    /** Unique lock duration in seconds. */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $submissionId,
        public readonly string $actionId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->submissionId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::googleAds(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(14)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     */
    public function handle(ProcessLeadConversionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->submissionId, $this->actionId);
        } catch (InsufficientDataException|MalformedStoredDataException $e) {
            $this->fail($e);
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     *
     * Delegates to {@see HandleLeadConversionFailureUseCase} which marks the
     * action as failed (preventing infinite cleanup loop).
     */
    public function failed(Throwable $exception): void
    {
        /** @var HandleLeadConversionFailureUseCase $useCase */
        $useCase = \app(HandleLeadConversionFailureUseCase::class);
        $useCase->execute(
            submissionId: $this->submissionId,
            actionId: $this->actionId,
            exceptionMessage: $exception->getMessage(),
            attempts: $this->attempts(),
        );
    }
}
