<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\StockUpdateFailedException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\ShopwiredRequestBuilderTrait;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * ShopWired Stock API Client.
 *
 * Handles bulk stock quantity updates via POST /stock endpoint.
 * Items are auto-batched (max 15 per request) and sent concurrently.
 *
 * Response validation ensures all items were updated by comparing
 * API's "updated" count against input count.
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
     * @param list<ItemStockLevel> $items
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws StockUpdateFailedException When updated count doesn't match expected
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function updateStockQuantity(array $items): void
    {
        if ($items === []) {
            return;
        }

        /** @var list<list<ItemStockLevel>> $batches */
        $batches = \array_chunk($items, self::BATCH_SIZE);
        $requests = self::buildPoolRequests($batches, self::ENDPOINT_STOCK, self::formatBatchData(...));
        $responses = $this->transport->poolPost($requests);

        $this->validateResponses($responses, $items);
    }

    /**
     * Format a batch of ItemStockLevel objects for the API.
     *
     * @param list<ItemStockLevel> $batch
     *
     * @return list<array{sku: string, quantity: int}>
     */
    private static function formatBatchData(array $batch): array
    {
        return \array_map(
            static fn(ItemStockLevel $item): array => [
                'sku' => $item->sku->value,
                'quantity' => $item->quantity,
            ],
            $batch,
        );
    }

    /**
     * Validate all batch responses and ensure total updated matches expected.
     *
     * @param array<string, Response> $responses
     * @param list<ItemStockLevel> $items Original items sent for update (for diagnostics)
     *
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws StockUpdateFailedException When updated count doesn't match expected
     */
    private function validateResponses(array $responses, array $items): void
    {
        $totalUpdated = 0;

        foreach ($responses as $response) {
            $totalUpdated += self::parseUpdatedResponse($response->json());
        }

        $expectedTotal = \count($items);

        if ($totalUpdated !== $expectedTotal) {
            throw new StockUpdateFailedException(
                expected: $expectedTotal,
                actual: $totalUpdated,
                reason: "Expected {$expectedTotal} items updated, but API reported {$totalUpdated}",
                attemptedItems: $items,
            );
        }
    }
}
