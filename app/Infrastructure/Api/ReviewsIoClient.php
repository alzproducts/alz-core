<?php

declare(strict_types=1);

namespace App\Infrastructure\Api;

use App\Domain\Review\Rating;
use App\Domain\Review\Validation\ValidSku;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\DataCollection;

final readonly class ReviewsIoClient
{
    private const string BASE_URL = 'https://api.reviews.co.uk/';
    private const int MAX_BATCH_SIZE = 100;

    public function __construct(
        private string $apiKey,
        private string $storeId,
        private int $timeout = 30,
        private int $retryTimes = 3,
        private int $retryDelay = 100,
    ) {}

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
                   ->retry($this->retryTimes, $this->retryDelay, throw: false)
                   ->withQueryParameters([
                       'apikey' => $this->apiKey,
                       'store'  => $this->storeId,
                   ])
                   ->timeout($this->timeout);
    }

    /**
     * Get product reviews by SKU in batch.
     *
     * @param string|string[] $skus Single SKU or array of SKUs
     *
     * @return DataCollection<int, Rating>
     * @throws ValidationException If SKU parameter is invalid
     * @throws RequestException|ConnectionException If API request fails
     */
    public function getProductRatingBatch(array|string $skus): DataCollection
    {
        $skuArray = \is_array($skus) ? $skus : [$skus];

        $validated = Validator::make(
            ['skus' => $skuArray],
            [
                'skus'   => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH_SIZE], // Batch limit
                'skus.*' => ['required', 'string', 'min:1', 'max:50', new ValidSku()],
            ],
        )->validate();

        /** @var array<string> $validatedSkus */
        $validatedSkus = $validated['skus'];

        $queryString = Arr::query([
            'sku' => \implode(';', $validatedSkus),
        ]);

        $response = $this->http()
                         ->get("product/rating-batch?{$queryString}")
                         ->throw();

        /** @var array<mixed> $data */
        $data = $response->json() ?? [];


        return Rating::collect($data, DataCollection::class);
    }

}
