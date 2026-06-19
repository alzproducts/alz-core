<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\SaleManagement\UseCases\ReconcileBulkSaleStateUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Scheduled safety net: scans all products for sale state drift.
 *
 * Runs on a schedule (e.g., hourly) to catch drift not handled by
 * the per-update reconciler. ShouldBeUnique prevents overlapping runs.
 */
final class ReconcileBulkSaleStateJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [300];

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'reconcile-bulk-sale-state';
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
        return \now()->addHours(1)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function handle(ReconcileBulkSaleStateUseCase $useCase): void
    {
        $useCase->execute();
    }
}
