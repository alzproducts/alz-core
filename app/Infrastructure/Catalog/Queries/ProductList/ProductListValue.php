<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Queries\ProductList;

use DateTimeImmutable;
use JsonException;
use LogicException;
use ValueError;
use Webmozart\Assert\Assert;

/**
 * Typed reads from a decoded row of the product list statement.
 *
 * The statement is ours, so a missing key or a wrong type is a broken contract between
 * the SQL and its row classes: it fails an assertion rather than throwing a domain exception.
 */
final class ProductListValue
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new LogicException('Product list statement returned invalid JSON', 0, $e);
        }

        Assert::isArray($decoded, 'Product list statement returned a non-object JSON value');

        return self::stringKeyed($decoded);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(mixed $row): array
    {
        Assert::object($row, 'Product list statement returned a non-object row');

        return self::stringKeyed(\get_object_vars($row));
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = self::get($row, $key);
        Assert::string($value, "Product list column {$key} must be a string");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        $value = self::get($row, $key);
        Assert::nullOrString($value, "Product list column {$key} must be a string or null");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = self::get($row, $key);
        Assert::integer($value, "Product list column {$key} must be an integer");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableInt(array $row, string $key): ?int
    {
        $value = self::get($row, $key);
        Assert::nullOrInteger($value, "Product list column {$key} must be an integer or null");

        return $value;
    }

    /**
     * Postgres numerics with no fractional digits decode as int, so both are accepted.
     *
     * @param array<string, mixed> $row
     */
    public static function float(array $row, string $key): float
    {
        $value = self::get($row, $key);
        Assert::true(\is_int($value) || \is_float($value), "Product list column {$key} must be a number");

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableFloat(array $row, string $key): ?float
    {
        return self::get($row, $key) === null ? null : self::float($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        $value = self::get($row, $key);
        Assert::boolean($value, "Product list column {$key} must be a boolean");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableBool(array $row, string $key): ?bool
    {
        $value = self::get($row, $key);
        Assert::nullOrBoolean($value, "Product list column {$key} must be a boolean or null");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function dateTime(array $row, string $key): DateTimeImmutable
    {
        try {
            $value = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', self::normaliseTimestamp(self::string($row, $key)));
        } catch (ValueError $e) {
            throw new LogicException('Product list timestamp is not in row_to_json format', 0, $e);
        }

        Assert::isInstanceOf($value, DateTimeImmutable::class, "Product list column {$key} must be a timestamp");

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableDateTime(array $row, string $key): ?DateTimeImmutable
    {
        return self::get($row, $key) === null ? null : self::dateTime($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<int>
     */
    public static function intList(array $row, string $key): array
    {
        $value = self::get($row, $key);
        Assert::isList($value, "Product list column {$key} must be a list");
        Assert::allInteger($value, "Product list column {$key} must hold integers");

        return $value;
    }

    /**
     * A JSON array or object of strings, read as its values.
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>|null
     */
    public static function nullableStringValues(array $row, string $key): ?array
    {
        $value = self::get($row, $key);

        if ($value === null) {
            return null;
        }

        Assert::isArray($value, "Product list column {$key} must be an array or null");
        Assert::allString($value, "Product list column {$key} must hold strings");

        return \array_values($value);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<array<string, mixed>>
     */
    public static function objectList(array $row, string $key): array
    {
        $value = self::get($row, $key);
        Assert::isList($value, "Product list column {$key} must be a list");

        return \array_map(static function (mixed $item) use ($key): array {
            Assert::isArray($item, "Product list column {$key} must hold objects");

            return self::stringKeyed($item);
        }, $value);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function object(array $row, string $key): array
    {
        $value = self::get($row, $key);
        Assert::isArray($value, "Product list column {$key} must be an object");

        return self::stringKeyed($value);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    public static function nullableObject(array $row, string $key): ?array
    {
        return self::get($row, $key) === null ? null : self::object($row, $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function get(array $row, string $key): mixed
    {
        Assert::keyExists($row, $key, "Product list row is missing column {$key}");

        return $row[$key] ?? null;
    }

    /**
     * row_to_json renders timestamptz as e.g. 2026-01-02T03:04:05.123+00:00, with the fraction omitted when zero.
     */
    private static function normaliseTimestamp(string $value): string
    {
        if (\preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?([+-]\d{2})(?::?(\d{2}))?$/', $value, $parts) !== 1) {
            throw new LogicException('Product list timestamp is not in row_to_json format');
        }

        $minutes = ($parts[4] ?? '') === '' ? '00' : $parts[4];

        return $parts[1] . '.' . \mb_substr($parts[2] . '000000', 0, 6) . $parts[3] . ':' . $minutes;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        Assert::allString(\array_keys($value), 'Product list JSON object keys must be strings');

        /** @var array<string, mixed> $value */
        return $value;
    }
}
