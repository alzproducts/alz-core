<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection - All DTOs are final classes */
/** @noinspection PhpRedundantCatchClauseInspection - CannotCreateData can be thrown by Spatie Data */

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Exceptions\CannotCreateData;

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
            self::logParsingFailure("Expected wrapped response with '{$key}' key", $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: "Expected wrapped response with '{$key}' key",
            );
        }

        $items = $data[$key] ?? [];

        if (!\is_array($items)) {
            self::logParsingFailure("Expected '{$key}' to be an array", $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: "Expected '{$key}' to be an array",
            );
        }

        try {
            /** @var list<T> */
            return \array_values(\array_map(
                static fn(mixed $item): Data => $dtoClass::from($item),
                $items,
            ));
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
            self::logParsingFailure('Expected direct array response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected direct array response',
            );
        }

        try {
            /** @var list<T> */
            return \array_map(
                static fn(mixed $item): Data => $dtoClass::from($item),
                $data,
            );
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
            self::logParsingFailure('Expected object response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected object response',
            );
        }

        try {
            /** @var T $dto */
            $dto = $dtoClass::from($data);

            return $dto->toDomain();
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
     * Log parsing failure for debugging API contract changes.
     */
    private static function logParsingFailure(string $error, mixed $data): void
    {
        Log::critical(self::SERVICE_NAME . ' API response validation failed', [
            'error' => $error,
            'raw_response' => $data,
        ]);
    }
}
