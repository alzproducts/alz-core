<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout;

use App\Domain\Exceptions\InvalidApiResponseException;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Response parsing utilities for HelpScout API responses.
 *
 * HelpScout uses HAL format with `_embedded.{resource}` structure.
 * This trait provides reusable methods for parsing these responses.
 *
 * @see https://developer.helpscout.com/mailbox-api/ HAL format documentation
 */
trait HelpScoutResponseParser
{
    private const string SERVICE_NAME = 'HelpScout';

    /**
     * Parse embedded collection from HelpScout HAL response.
     *
     * Combines validation, extraction, and DTO parsing in one call.
     * Use this for simple endpoints that return `_embedded.{key}` arrays.
     *
     * @template T of Data
     *
     * @param mixed $json Raw JSON response
     * @param string $key The embedded resource key (e.g., 'mailboxes', 'users')
     * @param class-string<T> $dtoClass Target DTO class
     *
     * @return array<T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseEmbeddedCollection(mixed $json, string $key, string $dtoClass): array
    {
        $this->validateArrayResponse($json, $key);

        /** @var array<mixed> $json */
        $embedded = $this->extractEmbedded($json, $key);

        /** @var array<T> */
        return $this->parseArrayResponse($embedded, $dtoClass)->all();
    }

    /**
     * Parse embedded collection and transform to Domain value objects.
     *
     * Combines parsing and domain transformation in one call.
     * Use this when the client interface expects Domain objects.
     *
     * @template TDto of Data
     * @template TDomain
     *
     * @param Response $response HTTP response from transport
     * @param string $key The embedded resource key (e.g., 'mailboxes', 'users')
     * @param class-string<TDto> $dtoClass Infrastructure DTO class
     * @param Closure(TDto): TDomain $toDomain Transformer to Domain value object
     *
     * @return list<TDomain>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseEmbeddedCollectionToDomain(
        Response $response,
        string $key,
        string $dtoClass,
        Closure $toDomain,
    ): array {
        /** @var array<TDto> $dtos */
        $dtos = $this->parseEmbeddedCollection($response->json(), $key, $dtoClass);

        return \array_values(\array_map($toDomain, $dtos));
    }

    /**
     * Find single item in embedded collection and transform to Domain value object.
     *
     * Returns null if no item matches. Use for lookup-by-field operations.
     *
     * @template TDto of Data
     * @template TDomain
     *
     * @param Response $response HTTP response from transport
     * @param string $key The embedded resource key
     * @param class-string<TDto> $dtoClass Target DTO class
     * @param Closure(TDto): bool $predicate Matcher function
     * @param Closure(TDto): TDomain $toDomain Transformer to Domain value object
     *
     * @return TDomain|null
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function findDomainInEmbeddedCollection(
        Response $response,
        string $key,
        string $dtoClass,
        Closure $predicate,
        Closure $toDomain,
    ): mixed {
        /** @var array<TDto> $items */
        $items = $this->parseEmbeddedCollection($response->json(), $key, $dtoClass);

        /** @var TDto|null $found */
        $found = \array_find($items, $predicate);

        return ($found !== null) ? $toDomain($found) : null;
    }

    /**
     * Validate that the response is an array.
     *
     * @throws InvalidApiResponseException When response is not an array
     */
    private function validateArrayResponse(mixed $json, string $context): void
    {
        if (!\is_array($json)) {
            self::logParsingFailure("Expected array response for {$context}", $json);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: "Expected array response for {$context}",
            );
        }
    }

    /**
     * Extract embedded array from HelpScout HAL response.
     *
     * @param array<mixed> $json Parsed JSON response
     * @param string $key The embedded resource key
     *
     * @return array<mixed>
     *
     * @throws InvalidApiResponseException When embedded structure is missing
     */
    private function extractEmbedded(array $json, string $key): array
    {
        /** @var array<string, mixed>|null $embeddedRoot */
        $embeddedRoot = $json['_embedded'] ?? null;

        if ($embeddedRoot === null) {
            self::logParsingFailure('Missing _embedded in response', $json);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Missing _embedded in response',
            );
        }

        /** @var array<mixed>|null $embedded */
        $embedded = $embeddedRoot[$key] ?? null;

        if ($embedded === null) {
            self::logParsingFailure("Missing _embedded.{$key} in response", $json);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: "Missing _embedded.{$key} in response",
            );
        }

        return $embedded;
    }

    /**
     * Parse API response expecting an array of DTOs.
     *
     * @template T of Data
     *
     * @param array<mixed> $data Raw array data from API
     * @param class-string<T> $dtoClass Target DTO class
     *
     * @return DataCollection<int, T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseArrayResponse(array $data, string $dtoClass): DataCollection
    {
        try {
            return $dtoClass::collect($data, DataCollection::class);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (CannotCreateData $e) {
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
