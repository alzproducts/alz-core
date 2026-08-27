<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Conversion\CallTracking;

use App\Application\Conversion\CallTracking\UseCases\HandleCallLeadConversionFailureUseCase;
use App\Application\Conversion\CallTracking\UseCases\ProcessCallLeadConversionUseCase;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
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
 * Uploads a call-sourced lead conversion to the given ad platform asynchronously.
 *
 * `ShouldBeUnique` is keyed on `visitId:platform` so the Google and Bing jobs for
 * the same visit never dedupe each other while one is still processing.
 */
final class ProcessCallLeadConversionJob extends AbstractJob implements ShouldBeUnique
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
        public readonly AdPlatform $platform,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->visitId . ':' . $this->platform->value;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $circuitBreaker = match ($this->platform) {
            AdPlatform::Google => ServiceCircuitBreaker::googleAds(),
            AdPlatform::Bing => ServiceCircuitBreaker::bingAdsRest(),
        };

        return [
            ...parent::middleware(),
            $circuitBreaker,
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
    public function handle(ProcessCallLeadConversionUseCase $useCase): void
    {
        try {
            $useCase->execute($this->visitId, $this->actionId, $this->callerPhone, $this->platform);
        } catch (InsufficientDataException|InvalidFormatException|UnsupportedConversionTypeException $e) {
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
