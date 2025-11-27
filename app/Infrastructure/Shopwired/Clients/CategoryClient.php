<?php

/** @noinspection PhpRedundantCatchClauseInspection */

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Domain\Catalog\ValueObjects\Category as DomainCategory;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\Category;
use App\Infrastructure\Shopwired\ShopwiredHttpTransport;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * ShopWired Categories API Client.
 *
 * Handles category retrieval operations from the ShopWired API.
 * HTTP concerns (auth, retry, timeout) are delegated to ShopwiredHttpTransport.
 *
 * @see https://shopwired.readme.io/docs/categories
 */
final readonly class CategoryClient implements CategoryClientInterface
{
    private const string SERVICE_NAME = 'Shopwired';

    private const string ENDPOINT_CATEGORIES = 'categories';

    public function __construct(
        private ShopwiredHttpTransport $transport,
    ) {}

    /**
     * @return list<DomainCategory>
     */
    public function listCategories(): array
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES);

        $dtos = $this->parseArrayResponse($response->json());

        $result = [];
        foreach ($dtos as $dto) {
            $result[] = $dto->toDomain();
        }

        return $result;
    }

    public function getCategoryById(int $id): DomainCategory
    {
        $response = $this->transport->get(self::ENDPOINT_CATEGORIES . '/' . $id);

        return $this->parseSingleResponse($response->json())->toDomain();
    }

    /**
     * Parse API response expecting an array of Category DTOs.
     *
     * @return DataCollection<int, Category>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseArrayResponse(mixed $data): DataCollection
    {
        if (! \is_array($data)) {
            self::logParsingFailure('Expected array response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected array response',
            );
        }

        try {
            return Category::collect($data, DataCollection::class);
        } catch (CannotCreateData $e) {
            self::logParsingFailure($e->getMessage(), $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'API returned invalid data structure',
                previous: $e,
            );
        }
    }

    /**
     * Parse API response expecting a single Category DTO.
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseSingleResponse(mixed $data): Category
    {
        if (! \is_array($data)) {
            self::logParsingFailure('Expected object response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected object response',
            );
        }

        try {
            return Category::from($data);
        } catch (CannotCreateData $e) {
            self::logParsingFailure($e->getMessage(), $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'API returned invalid data structure',
                previous: $e,
            );
        }
    }

    /**
     * Log parsing failure with context for debugging API contract changes.
     */
    private static function logParsingFailure(string $error, mixed $data): void
    {
        Log::critical(self::SERVICE_NAME . ' API response validation failed', [
            'error' => $error,
            'raw_response' => $data,
        ]);
    }
}
