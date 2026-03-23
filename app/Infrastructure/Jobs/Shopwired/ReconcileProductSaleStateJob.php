<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\SaleManagement\UseCases\ReconcileProductSaleStateUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\SaleSettings;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Reconciles a single product's sale state against DB reality.
 *
 * Dispatched with a 5-minute delay after price updates to allow
 * ShopWired webhooks to sync fresh state before checking.
 * ShouldBeUnique per product ID to avoid redundant reconciliations.
 */
final class ReconcileProductSaleStateJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public bool $failOnTimeout = true;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly IntId $productId,
        public readonly ?SaleSettings $saleSettings,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    public function uniqueId(): string
    {
        return 'reconcile-product-sale-state-' . $this->productId->value;
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
        return \now()->addHours(1)->toDateTimeImmutable();
    }

    /**
     * @throws ResourceNotFoundException When product not found in DB
     * @throws InvalidCustomFieldValueException When custom field mapping fails
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database unavailable
     */
    public function handle(ReconcileProductSaleStateUseCase $useCase): void
    {
        $useCase->execute($this->productId, $this->saleSettings);
    }
}
