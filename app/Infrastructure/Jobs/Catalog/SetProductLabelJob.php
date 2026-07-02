<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\Enums\CustomLabelField;
use App\Application\Contracts\Shopwired\ProductUpdateClientInterface;
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

final class SetProductLabelJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly IntId $productId,
        public readonly CustomLabelField $field,
        public readonly ?string $value,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    public function uniqueId(): string
    {
        return $this->productId->value . ':' . $this->field->value;
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
    public function handle(ProductUpdateClientInterface $updateClient): void
    {
        $updateClient->updateCustomFields(
            $this->productId->value,
            [$this->field->value => $this->value],
        );
    }
}
