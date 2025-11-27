<?php

/** @noinspection PhpPossiblePolymorphicInvocationInspection - All DTOs are final classes */
/** @noinspection PhpRedundantCatchClauseInspection - CannotCreateData can be thrown by Spatie Data */

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

use App\Domain\Exceptions\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertible;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Shared response parsing utilities for ShopWired API clients.
 *
 * Provides consistent parsing and error handling across all endpoint clients.
 * All methods are static for simplicity - no state management needed.
 *
 * @internal For use within ShopWired infrastructure only
 */
trait ShopwiredResponseParserTrait
{
    private const string SERVICE_NAME = 'Shopwired';

    /**
     * Parse API response expecting an array of DTOs.
     *
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return DataCollection<int, T>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseArrayResponse(mixed $data, string $dtoClass): DataCollection
    {
        if (! \is_array($data)) {
            self::logParsingFailure('Expected array response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected array response',
            );
        }

        try {
            return $dtoClass::collect($data, DataCollection::class);
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
     * Parse API response expecting a single DTO.
     *
     * @template T of Data
     *
     * @param class-string<T> $dtoClass
     *
     * @return T
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseSingleResponse(mixed $data, string $dtoClass): Data
    {
        if (! \is_array($data)) {
            self::logParsingFailure('Expected object response', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected object response',
            );
        }

        try {
            return $dtoClass::from($data);
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
     * Parse API response and convert single DTO to Domain object.
     *
     * Combines parsing and domain conversion in one step for cleaner client code.
     * The DTO class must implement DomainConvertible.
     *
     * @template T of Data&DomainConvertible
     *
     * @param class-string<T> $dtoClass
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseSingleToDomain(mixed $data, string $dtoClass): object
    {
        /** @var T $dto */
        $dto = self::parseSingleResponse($data, $dtoClass);

        return $dto->toDomain();
    }

    /**
     * Parse API response and convert array of DTOs to Domain objects.
     *
     * Combines parsing and domain conversion in one step for cleaner client code.
     * The DTO class must implement DomainConvertible.
     *
     * @template T of Data&DomainConvertible
     *
     * @param class-string<T> $dtoClass
     *
     * @return list<object>
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseArrayToDomain(mixed $data, string $dtoClass): array
    {
        $dtos = self::parseArrayResponse($data, $dtoClass);

        $result = [];
        foreach ($dtos as $dto) {
            /** @var T $dto */
            $result[] = $dto->toDomain();
        }

        return $result;
    }

    /**
     * Parse API response expecting a count object.
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private static function parseCountResponse(mixed $data): int
    {
        if (! \is_array($data) || ! isset($data['count']) || ! \is_int($data['count'])) {
            self::logParsingFailure('Expected count response with integer count field', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected count response',
            );
        }

        return $data['count'];
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
