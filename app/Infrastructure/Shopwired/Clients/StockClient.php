<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\ShopwiredRequestBuilderTrait;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ShopWired Stock API Client.
 *
 * Handles bulk stock quantity updates via POST /stock endpoint.
 * Items are auto-batched (max 15 per request) and sent concurrently.
 *
 * On a count mismatch within a batch (HTTP 200 but updated < batch size),
 * each item in that batch is retried individually to isolate failures.
 * This gives per-SKU observability without impacting healthy batches.
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
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable or connection fails
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws RuntimeException When HTTP pool initialization fails (Laravel/Guzzle internals)
     */
    public function updateStockQuantity(array $items): StockUpdateResult
    {
        if ($items === []) {
            return StockUpdateResult::empty();
        }

        /** @var list<list<ItemStockLevel>> $batches */
        $batches = \array_chunk($items, self::BATCH_SIZE);
        $requests = self::buildPoolRequests($batches, self::ENDPOINT_STOCK, self::formatBatchData(...));
        $responses = $this->transport->poolPost($requests);

        /** @var list<ItemStockLevel> $succeeded */
        $succeeded = [];
        /** @var list<ItemStockLevel> $failed */
        $failed = [];

        foreach ($batches as $index => $batch) {
            $key = "batch_{$index}";

            if (!\array_key_exists($key, $responses)) {
                throw new InvalidApiResponseException('ShopWired', "HTTP pool did not return a response for '{$key}'");
            }

            $updated = self::parseUpdatedResponse($responses[$key]->json());

            if ($updated === \count($batch)) {
                \array_push($succeeded, ...$batch);
            } else {
                // Count mismatch on HTTP 200 — fan out to individual requests to
                // isolate which specific SKUs ShopWired does not recognise.
                Log::warning('ShopWired stock batch count mismatch — retrying individually', [
                    'batch_index' => $index,
                    'expected'    => \count($batch),
                    'updated'     => $updated,
                ]);
                [$batchSucceeded, $batchFailed] = $this->retryBatchIndividually($batch);
                \array_push($succeeded, ...$batchSucceeded);
                \array_push($failed, ...$batchFailed);
            }
        }

        if ($failed !== []) {
            Log::error('ShopWired stock update: SKUs not updated after individual retry', [
                'failed_skus' => \array_map(static fn(ItemStockLevel $i): string => $i->sku->value, $failed),
                'failed_count' => \count($failed),
                'succeeded_count' => \count($succeeded),
            ]);
        }

        return new StockUpdateResult(succeeded: $succeeded, failed: $failed);
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
     * Retry each item in a mismatched batch individually to isolate failures.
     *
     * Only called when a batch returns HTTP 200 but with an updated count that
     * does not match the batch size. Transport errors (429, 5xx) on individual
     * requests are not caught here — they propagate for job-level retry.
     *
     * @param list<ItemStockLevel> $items
     *
     * @return array{0: list<ItemStockLevel>, 1: list<ItemStockLevel>} [succeeded, failed]
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    private function retryBatchIndividually(array $items): array
    {
        $succeeded = [];
        $failed = [];

        foreach ($items as $item) {
            $response = $this->transport->post(
                self::ENDPOINT_STOCK,
                self::formatBatchData([$item]),
            );

            $updated = self::parseUpdatedResponse($response->json());

            if ($updated === 1) {
                $succeeded[] = $item;
            } else {
                $failed[] = $item;
            }
        }

        return [$succeeded, $failed];
    }
}
