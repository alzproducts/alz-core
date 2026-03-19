<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\ShopwiredRequestBuilderTrait;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Stock API Client.
 *
 * Handles bulk stock quantity updates via POST /stock endpoint.
 * Items are auto-batched (max 15 per request) and sent concurrently.
 *
 * ShopWired returns HTTP 201 with {"updated": N} where N is the number of
 * items whose stock VALUE actually changed. Unknown SKUs are silently ignored
 * (updated=0), indistinguishable from idempotent pushes. Any HTTP 2xx
 * response (no transport exception) is treated as full batch success.
 *
 * HTTP concerns (auth, retry, timeout) delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/reference/stock
 */
final readonly class StockClient implements StockClientInterface
{
    use ShopwiredRequestBuilderTrait;
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_STOCK = 'stock';

    /**
     * Maximum items per API request (ShopWired limit).
     */
    private const int BATCH_SIZE = 15;

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @param list<ItemStockLevel> $items
     *
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails
     */
    public function updateStockQuantity(array $items): StockUpdateResult
    {
        if ($items === []) {
            return StockUpdateResult::empty();
        }

        /** @var list<list<ItemStockLevel>> $batches */
        $batches = \array_chunk($items, self::BATCH_SIZE);
        $requests = self::buildPoolRequests($batches, self::ENDPOINT_STOCK, self::formatBatchData(...));
        $poolResult = $this->transport->poolPost($requests);

        /** @var list<ItemStockLevel> $pushed */
        $pushed = [];

        foreach ($batches as $index => $batch) {
            $key = "batch_{$index}";

            if (!\array_key_exists($key, $poolResult->responses)) {
                continue; // This batch failed at transport level — captured in $poolResult->transportFailures
            }

            // Validate response structure. updated=N reflects items whose stock value
            // changed; updated=0 is valid for idempotent pushes or unknown SKUs (both
            // silently accepted by ShopWired). Any 2xx with no transport exception = success.
            self::parseUpdatedResponse($poolResult->responses[$key]->json());

            \array_push($pushed, ...$batch);
        }

        return new StockUpdateResult(
            pushed: $pushed,
            transportFailures: $poolResult->transportFailures,
        );
    }

    /**
     * Format a batch of ItemStockLevel objects for the API.
     *
     * The stock endpoint expects a wrapped object: {"items": [{sku, quantity}, ...]}.
     *
     * @param list<ItemStockLevel> $batch
     *
     * @return array{items: list<array{sku: string, quantity: int}>}
     */
    private static function formatBatchData(array $batch): array
    {
        return [
            'items' => \array_map(
                static fn(ItemStockLevel $item): array => [
                    'sku' => $item->sku->value,
                    'quantity' => $item->quantity,
                ],
                $batch,
            ),
        ];
    }
}
