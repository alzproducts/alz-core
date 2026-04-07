<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\PriceUpdateClientInterface;
use App\Application\Shopwired\PricingUpdate\Results\PriceUpdateClientResult;
use App\Domain\Catalog\Product\Commands\UpdatePriceCommand;
use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;
use App\Infrastructure\Shopwired\PoolPostResult;
use App\Infrastructure\Shopwired\Responses\PriceUpdateItemResponse;
use App\Infrastructure\Shopwired\ShopwiredRequestBuilderTrait;
use App\Infrastructure\Shopwired\ShopwiredResponseParserTrait;

/**
 * ShopWired Price Update API Client.
 *
 * Handles batch price updates via POST /products/prices endpoint.
 * Items are auto-batched (max 15 per request) and sent concurrently.
 *
 * ShopWired returns a JSON array of per-item results indicating whether
 * each SKU was updated. Per-item failures (updated: false) are included
 * in the results for the caller to classify.
 *
 * HTTP concerns (auth, retry, timeout) delegated to ShopwiredHttpTransport.
 */
final readonly class PriceUpdateClient implements PriceUpdateClientInterface
{
    use ShopwiredRequestBuilderTrait;
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT_PRICES = 'products/prices';

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
     * @throws InvalidApiResponseException When response parsing fails (API contract violation)
     * @throws ExternalServiceUnavailableException When HTTP pool initialization fails
     */
    public function updatePrices(array $commands): PriceUpdateClientResult
    {
        if ($commands === []) {
            return new PriceUpdateClientResult(results: []);
        }

        /** @var list<list<UpdatePriceCommand>> $batches */
        $batches = \array_chunk($commands, self::BATCH_SIZE);
        $requests = self::buildPoolRequests($batches, self::ENDPOINT_PRICES, self::formatBatchData(...));
        $poolResult = $this->transport->poolPost($requests);

        return new PriceUpdateClientResult(
            results: self::collectBatchResults($batches, $poolResult),
            transportFailures: $poolResult->transportFailures,
        );
    }

    /**
     * Collect parsed per-item results from all successful batch responses.
     *
     * Batches missing from $poolResult->responses failed at transport level
     * and are already captured in $poolResult->transportFailures — skip them.
     *
     * @param list<list<UpdatePriceCommand>> $batches
     *
     * @return list<PriceUpdateItemResult>
     *
     * @throws InvalidApiResponseException When response parsing fails
     */
    private static function collectBatchResults(array $batches, PoolPostResult $poolResult): array
    {
        /** @var list<PriceUpdateItemResult> $results */
        $results = [];

        foreach (\array_keys($batches) as $index) {
            $batchResults = self::parseBatchResponse($index, $poolResult);

            if ($batchResults !== null) {
                \array_push($results, ...$batchResults);
            }
        }

        return $results;
    }

    /**
     * Parse a single batch response. Returns null if the batch failed at transport level.
     *
     * @return list<PriceUpdateItemResult>|null
     *
     * @throws InvalidApiResponseException When response parsing fails
     */
    private static function parseBatchResponse(int $index, PoolPostResult $poolResult): ?array
    {
        $key = "batch_{$index}";

        if (! \array_key_exists($key, $poolResult->responses)) {
            return null;
        }

        /** @var list<PriceUpdateItemResult> $results */
        $results = self::parseArrayToDomain(
            $poolResult->responses[$key]->json(),
            PriceUpdateItemResponse::class,
        );

        return $results;
    }

    /**
     * Format a batch of UpdatePriceCommand objects for the API.
     *
     * The price endpoint expects: {"items": [{sku, price?, salePrice?}, ...], "sendToEbay": false}.
     * Only non-null price fields are included (null = no change).
     *
     * @param list<UpdatePriceCommand> $batch
     *
     * @return array{items: list<array<string, string|float>>, sendToEbay: false}
     */
    private static function formatBatchData(array $batch): array
    {
        return [
            'items' => \array_map(self::formatItem(...), $batch),
            'sendToEbay' => false,
        ];
    }

    /**
     * Format a single command for the API payload.
     *
     * @return array<string, string|float>
     */
    private static function formatItem(UpdatePriceCommand $command): array
    {
        $item = ['sku' => $command->sku->value];

        if ($command->price !== null) {
            $item['price'] = $command->price->toGross();
        }

        if ($command->salePrice !== null) {
            $item['salePrice'] = $command->salePrice->toGross();
        }

        if ($command->rrp !== null) {
            $item['comparePrice'] = $command->rrp->toGross();
        }

        return $item;
    }
}
