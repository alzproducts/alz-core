<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SyncRelatedProductsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Daily orchestrator: run the related products algorithm and dispatch per-product
 * custom field updates for products whose related products list has changed.
 *
 * Does NOT call the ShopWired API directly — only queries the database and
 * dispatches UpdateProductCustomFieldsJob per changed product.
 */
final class SyncRelatedProductsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    /** @var array<int> */
    public array $backoff = [30, 60];

    public function uniqueId(): string
    {
        return 'sync-related-products';
    }

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

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addMinutes(45)->toDateTimeImmutable();
    }

    /**
     * @throws ResourceNotFoundException When no active algorithm params row exists
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(SyncRelatedProductsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
