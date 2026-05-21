<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SnapshotSkuPopularityRankingUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Weekly job: inserts snapshot rows into catalog.sku_popularity_snapshots
 * by reading from catalog.sku_popularity_ranking (expensive view) and stamping
 * CURRENT_DATE as the snapshot_date.
 *
 * The composite PK on (snapshot_date, live_sku) ensures accidental
 * double-fires on the same day throw DuplicateRecordException rather than
 * silently overwriting history.
 *
 * Scheduled Sunday 03:00 Europe/London via CatalogScheduleServiceProvider.
 * Timeout 3600s matches the `low` queue tier.
 *
 * @see SnapshotSkuPopularityRankingUseCase
 */
final class SyncSkuPopularityRankingSnapshotJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

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
            new HandleDatabaseExceptions(),
        ];
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(SnapshotSkuPopularityRankingUseCase $useCase): void
    {
        $useCase->execute();
    }
}
