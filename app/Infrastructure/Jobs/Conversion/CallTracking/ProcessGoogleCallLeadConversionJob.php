<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Conversion\CallTracking;

use App\Application\Conversion\CallTracking\UseCases\HandleCallLeadConversionFailureUseCase;
use App\Application\Conversion\CallTracking\UseCases\ProcessGoogleCallLeadConversionUseCase;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Throwable;

/**
 * `ShouldBeUnique` keyed by visit ID — Laravel FQCN-prefixes the lock so this won't
 * collide with `ProcessBingCallLeadConversionJob`'s lock for the same visit.
 */
final class ProcessGoogleCallLeadConversionJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [60, 300, 3600, 43200];

    public int $uniqueFor = 300;

    public function __construct(
        public readonly string $visitId,
        public readonly string $actionId,
        public readonly string $callerPhone,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->visitId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
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
     * @throws DuplicateRecordException
     */
    public function handle(ProcessGoogleCallLeadConversionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->visitId, $this->actionId, $this->callerPhone);
        } catch (InsufficientDataException|InvalidFormatException $e) {
            $this->fail($e);
        }
    }

    public function failed(Throwable $exception): void
    {
        /** @var HandleCallLeadConversionFailureUseCase $useCase */
        $useCase = \app(HandleCallLeadConversionFailureUseCase::class);
        $useCase->execute(
            visitId: $this->visitId,
            actionId: $this->actionId,
            exceptionMessage: $exception->getMessage(),
            attempts: $this->attempts(),
        );
    }
}
