<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Conversion;

use App\Application\Conversion\Enums\AdPlatform;
use App\Application\Conversion\QuoteConversionDetailsDTO;
use App\Application\Conversion\UseCases\ProcessQuoteConversionUseCase;
use App\Domain\ContactSubmission\Exceptions\ActionTypeMismatchException;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Data\AbstractDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Uploads a quote conversion to the given ad platform asynchronously.
 *
 * `ShouldBeUnique` is keyed on `submissionId:platform` so per-platform jobs for the
 * same submission never dedupe each other, and this never collides with
 * {@see ProcessLeadConversionJob} for the same submission.
 *
 * Exception Strategy mirrors {@see ProcessLeadConversionJob}:
 * - TransientApiFailure: Handled by {@see HandleApiExceptions} middleware (release/rethrow)
 * - PermanentApiFailure: Handled by {@see HandleApiExceptions} middleware (fail immediately)
 * - Data exceptions, unsupported conversion type, misrouted action: Caught in handle() → fail immediately
 * - Unexpected Throwable: Retried by Laravel; the action row stays `processing` until the lease sweep
 */
final class ProcessQuoteConversionJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum attempts before permanent failure.
     * 5 attempts = initial + 4 retries (using all 4 backoff delays).
     */
    public int $tries = 5;

    public int $maxExceptions = 5;

    public int $timeout = 75;

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
        public readonly float $value,
        public readonly string $convertedAt,
        public readonly AdPlatform $platform,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->submissionId . ':' . $this->platform->value;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        $circuitBreaker = match ($this->platform) {
            AdPlatform::Google => ServiceCircuitBreaker::googleAds(),
            AdPlatform::Bing => ServiceCircuitBreaker::bingAdsRest(),
        };

        // intentional: HandleApiExceptions wraps the breaker so the breaker still counts transients carrying retryAfter
        return [
            ...parent::middleware(),
            new HandleApiExceptions(),
            $circuitBreaker,
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
    public function handle(ProcessQuoteConversionUseCase $useCase): void
    {
        try {
            $useCase->execute(
                $this->submissionId,
                $this->actionId,
                new QuoteConversionDetailsDTO($this->value, $this->convertedAt),
                $this->platform,
            );
        } catch (AbstractDataException|UnsupportedConversionTypeException|ActionTypeMismatchException $e) {
            $this->fail($e);
        }
    }
}
