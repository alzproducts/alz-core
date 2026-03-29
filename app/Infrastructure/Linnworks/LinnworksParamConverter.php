<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\Api\InvalidApiRequestException;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Converts mixed parameter types to Linnworks form-compatible values.
 *
 * Arrays are JSON-encoded, booleans become string literals.
 * Stateless — all methods are static.
 */
final class LinnworksParamConverter
{
    private const string SERVICE_NAME = 'Linnworks';

    /**
     * Convert mixed params to form-compatible string values.
     *
     * @param array<string, scalar|array<mixed>|null> $params
     *
     * @return array<string, string|int|float>
     *
     * @throws InvalidApiRequestException When array serialization fails
     */
    public static function convertToFormParams(array $params, string $endpoint): array
    {
        $formParams = [];

        foreach ($params as $key => $value) {
            if ($value === null) {
                // Linnworks may not ignore null - monitor for issues
                continue;
            }

            $formParams[$key] = match (true) {
                \is_array($value) => self::jsonEncodeParam($key, $value, $endpoint),
                \is_bool($value) => $value ? 'true' : 'false',
                \is_int($value), \is_float($value) => $value,
                \is_string($value) => $value,
            };
        }

        return $formParams;
    }

    /**
     * JSON-encode an array parameter for form submission.
     *
     * @param array<mixed> $value
     *
     * @throws InvalidApiRequestException When serialization fails
     */
    private static function jsonEncodeParam(string $key, array $value, string $endpoint): string
    {
        try {
            return \json_encode($value, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Log::error(self::SERVICE_NAME . ' failed to serialize form parameter', [
                'endpoint' => $endpoint,
                'parameter' => $key,
                'error' => $e->getMessage(),
            ]);

            throw new InvalidApiRequestException(
                self::SERVICE_NAME,
                "Parameter '{$key}' could not be serialized: " . $e->getMessage(),
                $e,
            );
        }
    }
}
