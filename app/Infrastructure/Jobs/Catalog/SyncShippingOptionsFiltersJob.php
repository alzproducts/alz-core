<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SyncShippingOptionsFiltersUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * 10-minute orchestrator: query products with changed Shipping Options filters and dispatch per-entity updates.
 *
 * Does NOT call the ShopWired API directly — only queries the database view
 * and dispatches UpdateProductFilterJob per product.
 *
 * Tighter retry parameters than hourly siblings: $tries=3 and retryUntil=9min
 * fit all attempts (t=0, t=30, t=90) within the 9-minute window.
 *
 * @see SyncShippingOffersFiltersJob for the separate "Shipping Offers" filter (optionNo 20)
 */
final class SyncShippingOptionsFiltersJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 120;
    /** Tighter TTL than hourly siblings because this runs every 10 minutes. */
    public int $uniqueFor = 600;

    /** @var array<int> */
    public array $backoff = [30, 60];

    public function uniqueId(): string
    {
        return 'sync-shipping-options-filters';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleDatabaseExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addMinutes(9)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function handle(SyncShippingOptionsFiltersUseCase $useCase): void
    {
        $useCase->execute();
    }
}
