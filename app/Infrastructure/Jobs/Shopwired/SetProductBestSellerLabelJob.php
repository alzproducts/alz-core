<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Catalog\UseCases\SetProductBestSellerLabelUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Update the Best Sellers label on a single product's custom_label_4 field.
 *
 * Receives pre-computed target labels from the orchestrator — applies them
 * via {@see SetProductBestSellerLabelUseCase} which calls ProductUpdateClientInterface
 * directly (bypasses CustomFieldSubmissionValidator).
 */
final class SetProductBestSellerLabelJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    /** @var array<int> */
    public array $backoff = [60, 300, 900];

    /**
     * @param list<string>|null $targetLabels
     */
    public function __construct(
        public readonly IntId $productId,
        public readonly ?array $targetLabels,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    public function uniqueId(): string
    {
        return 'set-best-seller-label-' . $this->productId->value;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ServiceRateLimiter::shopwiredApiBulk(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * @throws ResourceNotAvailableException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    public function handle(SetProductBestSellerLabelUseCase $useCase): void
    {
        $useCase->execute($this->productId, $this->targetLabels);
    }
}
