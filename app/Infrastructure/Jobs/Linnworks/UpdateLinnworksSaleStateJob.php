<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\Enums\ExtendedPropertyName;
use App\Domain\Inventory\ValueObjects\ExtendedPropertyWrite;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Updates is_in_sale EP on Linnworks.
 *
 * - Added to sale: sets is_in_sale = '1'
 * - Removed from sale: sets is_in_sale = '0'
 *
 * Idempotent — always writes the EP regardless of current value.
 */
final class UpdateLinnworksSaleStateJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 1200];

    public bool $failOnTimeout = true;

    public int $timeout = 30;

    public function __construct(
        public readonly Sku $sku,
        public readonly bool $addedToSale,
    ) {
        $this->onQueue(QueueName::Bulk->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(2)->toDateTimeImmutable();
    }

    /**
     * @throws ResourceNotFoundException When stock item not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function handle(InventoryUpdateClientInterface $inventoryUpdateClient): void
    {
        $inventoryUpdateClient->setExtendedProperties($this->sku, [
            ExtendedPropertyWrite::create(
                ExtendedPropertyName::IsInSale,
                $this->addedToSale ? '1' : '0',
            ),
        ]);
    }
}
