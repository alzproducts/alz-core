<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection - All DTOs are final classes */
/** @noinspection PhpRedundantCatchClauseInspection - CannotCreateData can be thrown by Spatie Data */

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Support;

use App\Domain\Exceptions\InvalidApiResponseException;
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
