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
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Syncs SellingPriceGross and SellingPriceNet EPs to Linnworks on price update.
 *
 * Dispatched per SKU when retail pricing changes.
 */
final class UpdateLinnworksSellingPriceEpsJob implements ShouldQueue
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
        public readonly Money $effectivePrice,
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
        $gross = \number_format($this->effectivePrice->toGross(), 2, '.', '');
        $net = \number_format($this->effectivePrice->toNet(), 2, '.', '');

        $inventoryUpdateClient->setExtendedProperties($this->sku, [
            ExtendedPropertyWrite::create(ExtendedPropertyName::SellingPriceGross, $gross),
            ExtendedPropertyWrite::create(ExtendedPropertyName::SellingPriceNet, $net),
        ]);
    }
}
