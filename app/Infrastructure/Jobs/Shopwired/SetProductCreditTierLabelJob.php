<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Catalog\Enums\CreditTier;
use App\Application\Catalog\UseCases\SetProductCreditTierLabelUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use App\Infrastructure\Jobs\Middleware\ServiceRateLimiter;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Update the credit-tier label on a single product's custom_label_0 field.
 *
 * Receives the pre-computed target tier (nullable — null clears the label
 * when a product leaves credit sales) from the orchestrator.
 */
final class SetProductCreditTierLabelJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 60;
    /** @var array<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly IntId $productId,
        public readonly ?CreditTier $tier,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    public function uniqueId(): string
    {
        return (string) $this->productId->value;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
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
    public function handle(SetProductCreditTierLabelUseCase $useCase): void
    {
        $useCase->execute($this->productId, $this->tier);
    }
}
