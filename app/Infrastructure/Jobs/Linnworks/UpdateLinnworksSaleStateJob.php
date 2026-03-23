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
 * Updates is_in_sale and last_sale_end_date EPs on Linnworks.
 *
 * - Added to sale: sets is_in_sale = '1'
 * - Removed from sale: sets is_in_sale = '0' and last_sale_end_date = now
 *
 * Fixes legacy bug: auto-removals now always update Linnworks EPs.
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
        $properties = $this->addedToSale
            ? [ExtendedPropertyWrite::create(ExtendedPropertyName::IsInSale, '1')]
            : [
                ExtendedPropertyWrite::create(ExtendedPropertyName::IsInSale, '0'),
                ExtendedPropertyWrite::create(ExtendedPropertyName::LastSaleEndDate, \now()->format('Y-m-d H:i:s')),
            ];

        $inventoryUpdateClient->setExtendedProperties($this->sku, $properties);
    }
}
