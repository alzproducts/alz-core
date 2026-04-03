<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Operations;

use App\Application\Operations\UseCases\RecordPricePeriodUseCase;
use App\Domain\Catalog\Product\ValueObjects\ProductRetailPricing;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Records SCD2 price period when a SKU's retail pricing changes.
 *
 * Database-only job — HandleDatabaseExceptions middleware handles permanent
 * failures (immediate fail) while transient ExternalServiceUnavailableException
 * bubbles to the Worker for standard backoff retry.
 */
final class RecordPricePeriodJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> 1min, 5min, 20min */
    public array $backoff = [60, 300, 1200];

    public bool $failOnTimeout = true;

    public int $timeout = 30;

    public function __construct(
        public readonly Sku $sku,
        public readonly ProductRetailPricing $newPrices,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new HandleDatabaseExceptions(),
        ];
    }

    /**
     * @throws DatabaseOperationFailedException On permanent database failure
     * @throws DuplicateRecordException On unique constraint violation
     */
    public function handle(RecordPricePeriodUseCase $useCase): void
    {
        $useCase->execute($this->sku, $this->newPrices);
    }
}
