<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Conversion;

use App\Application\Conversion\UseCases\HandleLeadConversionFailureUseCase;
use App\Application\Conversion\UseCases\ProcessBingLeadConversionUseCase;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
 * `ShouldBeUnique` keyed by submission ID. Laravel FQCN-prefixes the lock so it does
 * not collide with `ProcessLeadConversionJob`'s lock for the same submission.
 */
final class ProcessBingLeadConversionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Initial attempt + one retry per `$backoff` delay. */
    public int $tries = 5;

    public int $maxExceptions = 5;

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [60, 300, 3600, 43200];

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
            ServiceCircuitBreaker::bingAdsRest(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(14)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(ProcessBingLeadConversionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->submissionId, $this->actionId);
        } catch (InsufficientDataException|InvalidFormatException|MalformedStoredDataException|UnsupportedConversionTypeException $e) {
            $this->fail($e);
        }
    }

    /**
     * Delegates to {@see HandleLeadConversionFailureUseCase} — marks the action Failed
     * directly rather than dispatching another job, to avoid an infinite cleanup loop.
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
