<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection - All DTOs are final classes */
/** @noinspection PhpRedundantCatchClauseInspection - CannotCreateData can be thrown by Spatie Data */

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Closure;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Throwable;

/**
 * Shared response parsing utilities for Linnworks API clients.
 *
 * Provides consistent parsing and error handling across all endpoint clients.
 * Catches Spatie DTO validation failures and translates to domain exceptions
 * with CRITICAL logging for debugging API contract changes.
 *
 * @internal For use within Linnworks infrastructure only
 */
trait LinnworksResponseParserTrait
{
    private const string SERVICE_NAME = 'Linnworks';

    /**
     * Parse wrapped API response and return array of DTOs.
     *
     * Linnworks often returns items wrapped: {Items: [...]}
     *
     * @template T of Data
     *
     * @param string $key The key containing the items array (default: 'Items')
     * @param class-string<T> $dtoClass
     *
     * @return list<T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseWrappedArray(mixed $data, string $dtoClass, string $key = 'Items'): array
    {
        if (!\is_array($data)) {
            self::throwParsingFailure("Expected wrapped response with '{$key}' key", $data);
        }

        $items = $data[$key] ?? [];

        if (!\is_array($items)) {
            self::throwParsingFailure("Expected '{$key}' to be an array", $data);
        }

        /** @var list<T> */
        return self::mapDtosFromArray($items, $dtoClass, $data);
    }

    /**
     * Parse direct array API response and return array of DTOs.
     *
     * Some Linnworks endpoints return a direct array [...] instead of wrapped {Items: [...]}.
     *
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return list<T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseDirectArray(mixed $data, string $dtoClass): array
    {
        if (!\is_array($data) || !\array_is_list($data)) {
            self::throwParsingFailure('Expected direct array response', $data);
        }

        /** @var list<T> */
        return self::mapDtosFromArray($data, $dtoClass, $data);
    }

    /**
     * Parse wrapped API response and return array of Domain objects.
     *
     * Combines parseWrappedArray() with toDomain() conversion in a single step.
     * Callers should use @var annotation to specify the concrete domain type.
     *
     * @template T of Data&DomainConvertibleInterface
     *
     * @param string $key The key containing the items array (default: 'Items')
     * @param class-string<T> $dtoClass
     *
     * @return list<object>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseWrappedArrayToDomain(mixed $data, string $dtoClass, string $key = 'Items'): array
    {
        /** @var list<T> $dtos */
        $dtos = self::parseWrappedArray($data, $dtoClass, $key);

        return \array_map(
            static fn(DomainConvertibleInterface $dto): object => $dto->toDomain(),
            $dtos,
        );
    }

    /**
     * Parse direct array API response and return array of Domain objects.
     *
     * Combines parseDirectArray() with toDomain() conversion in a single step.
     * Callers should use @var annotation to specify the concrete domain type.
     *
     * @template T of Data&DomainConvertibleInterface
     *
     * @param class-string<T> $dtoClass
     *
     * @return list<object>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseDirectArrayToDomain(mixed $data, string $dtoClass): array
    {
        /** @var list<T> $dtos */
        $dtos = self::parseDirectArray($data, $dtoClass);

        return \array_map(
            static fn(DomainConvertibleInterface $dto): object => $dto->toDomain(),
            $dtos,
        );
    }

    /**
     * Parse single response and convert to Domain object.
     *
     * @template T of Data&DomainConvertibleInterface
     *
     * @param class-string<T> $dtoClass
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseSingleToDomain(mixed $data, string $dtoClass): object
    {
        if ($data === null || !\is_array($data)) {
            self::throwParsingFailure('Expected object response', $data);
        }

        try {
            /** @var T $dto */
            $dto = $dtoClass::from($data);

            return $dto->toDomain();
        } catch (CannotCreateData $e) {
            self::throwParsingFailure($e->getMessage(), $data, $e);
        }
    }

    /**
     * Validate that a response is an array and return it.
     *
     * Used for endpoints that return raw arrays without converting to domain objects.
     *
     * @return array<array-key, mixed>
     *
     * @throws InvalidApiResponseException When response is not an array
     */
    private static function validateArrayResponse(mixed $data, string $context): array
    {
        if (!\is_array($data)) {
            self::logParsingFailure($context, $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: $context,
            );
        }

        return $data;
    }

    /**
     * Group domain objects by a GUID key extracted via callback.
     *
     * Keys are lowercased for case-insensitive matching (Linnworks GUID casing is inconsistent).
     * Items where the callback returns null are silently skipped.
     *
     * @template T
     *
     * @param list<T> $items
     * @param Closure(T): ?string $keyExtractor Returns the GUID string to group by, or null to skip
     *
     * @return array<string, list<T>>
     */
    private static function groupByGuid(array $items, Closure $keyExtractor): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $key = $keyExtractor($item);

            if ($key !== null) {
                $grouped[\mb_strtolower($key)][] = $item;
            }
        }

        return $grouped;
    }

    /**
     * Log and throw an InvalidApiResponseException.
     *
     * When $previous is non-null, the failure originated from Spatie DTO validation
     * (API contract drift); the thrown message is generic and $previous is chained.
     * Otherwise the failure is a structural guard and the message is used verbatim.
     *
     * @throws InvalidApiResponseException Always.
     */
    private static function throwParsingFailure(string $message, mixed $data, ?Throwable $previous = null): never
    {
        self::logParsingFailure($message, $data);

        throw new InvalidApiResponseException(
            serviceName: self::SERVICE_NAME,
            message: $previous !== null ? 'API returned invalid data structure' : $message,
            previous: $previous,
        );
    }

    /**
     * Map each item through $dtoClass::from(), translating CannotCreateData into
     * InvalidApiResponseException. Result is always a list (re-indexed).
     *
     * @template T of Data
     *
     * @param array<array-key, mixed> $items
     * @param class-string<T> $dtoClass
     *
     * @return list<T>
     *
     * @throws InvalidApiResponseException When any item fails DTO validation.
     */
    private static function mapDtosFromArray(array $items, string $dtoClass, mixed $rawData): array
    {
        try {
            /** @var list<T> */
            return \array_values(\array_map(
                static fn(mixed $item): Data => $dtoClass::from($item),
                $items,
            ));
        } catch (CannotCreateData $e) {
            self::throwParsingFailure($e->getMessage(), $rawData, $e);
        }
    }

    /**
     * Log parsing failure for debugging API contract changes.
     */
    private static function logParsingFailure(string $error, mixed $data): void
    {
        Log::critical(self::SERVICE_NAME . ' API response validation failed', [
            'error' => $error,
            'response_type' => \get_debug_type($data),
        ]);
    }
}
