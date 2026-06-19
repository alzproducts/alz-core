<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SnapshotCreditProductPopularityRankingUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Weekly job: insert one row per product into
 * catalog.credit_product_popularity_snapshots by reading from
 * catalog.credit_product_popularity_ranking and stamping CURRENT_DATE.
 *
 * Composite PK on (snapshot_date, parent_external_id) ensures accidental
 * double-fires throw DuplicateRecordException instead of overwriting history.
 *
 * Scheduled Sunday 03:00 Europe/London via CatalogScheduleServiceProvider.
 *
 * @see SnapshotCreditProductPopularityRankingUseCase
 */
final class SyncCreditProductPopularityRankingSnapshotJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $timeout = 3600;
    /** 6 hours — blocks double-fires within the same run window */
    public int $uniqueFor = 21600;

    /** @var list<int> */
    public array $backoff = [60, 300];

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

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(SnapshotCreditProductPopularityRankingUseCase $useCase): void
    {
        $useCase->execute();
    }
}
