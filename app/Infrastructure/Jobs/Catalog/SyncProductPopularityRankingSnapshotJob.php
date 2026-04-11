<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SnapshotProductPopularityRankingUseCase;
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
 * Weekly job: inserts ~2,500 snapshot rows into catalog.product_popularity_snapshots
 * by reading from catalog.product_popularity_ranking (expensive view) and stamping
 * CURRENT_DATE as the snapshot_date.
 *
 * The composite PK on (snapshot_date, parent_external_id) ensures accidental
 * double-fires on the same day throw DuplicateRecordException rather than
 * silently overwriting history.
 *
 * Scheduled Sunday 03:00 Europe/London via CatalogScheduleServiceProvider.
 * Timeout 3600s matches the `low` queue tier (see app/Infrastructure/Jobs/CLAUDE.md).
 *
 * @see SnapshotProductPopularityRankingUseCase
 */
final class SyncProductPopularityRankingSnapshotJob implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        return 'sync-product-popularity-ranking-snapshot';
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
    public function handle(SnapshotProductPopularityRankingUseCase $useCase): void
    {
        $useCase->execute();
    }
}
