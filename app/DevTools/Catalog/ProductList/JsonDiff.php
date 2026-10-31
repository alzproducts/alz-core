<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use stdClass;

/**
 * Field-level differences between two decoded JSON documents, one line per difference, addressed by JSON path.
 * Decoded with objects as stdClass so `{}` and `[]` stay distinct.
 */
final class JsonDiff
{
    private const int PREVIEW_LENGTH = 160;

    /**
     * @return list<string>
     */
    public static function diff(mixed $expected, mixed $actual, string $path = '$'): array
    {
        return match (true) {
            $expected instanceof stdClass && $actual instanceof stdClass => self::diffObjects($expected, $actual, $path),
            \is_array($expected) && \is_array($actual) => self::diffLists($expected, $actual, $path),
            default => self::diffValues($expected, $actual, $path),
        };
    }

    /**
     * @return list<string>
     */
    private static function diffObjects(stdClass $expected, stdClass $actual, string $path): array
    {
        $expectedFields = \get_object_vars($expected);
        $actualFields = \get_object_vars($actual);
        $differences = [];

        foreach ($expectedFields as $key => $value) {
            \array_push($differences, ...(\array_key_exists($key, $actualFields)
                ? self::diff($value, $actualFields[$key], "{$path}.{$key}")
                : ["{$path}.{$key}: missing (was " . self::preview($value) . ')']));
        }

        foreach (\array_diff_key($actualFields, $expectedFields) as $key => $value) {
            $differences[] = "{$path}.{$key}: unexpected (is " . self::preview($value) . ')';
        }

        return [...$differences, ...self::diffKeyOrder($expectedFields, $actualFields, $path)];
    }

    /**
     * @param array<mixed> $expectedFields
     * @param array<mixed> $actualFields
     *
     * @return list<string>
     */
    private static function diffKeyOrder(array $expectedFields, array $actualFields, string $path): array
    {
        $expectedOrder = \array_keys(\array_intersect_key($expectedFields, $actualFields));
        $actualOrder = \array_keys(\array_intersect_key($actualFields, $expectedFields));

        return $expectedOrder === $actualOrder ? [] : ["{$path}: key order differs"];
    }

    /**
     * @param array<mixed> $expected
     * @param array<mixed> $actual
     *
     * @return list<string>
     */
    private static function diffLists(array $expected, array $actual, string $path): array
    {
        $differences = \count($expected) === \count($actual)
            ? []
            : ["{$path}: length " . \count($expected) . ' -> ' . \count($actual)];

        foreach ($expected as $index => $value) {
            if (\array_key_exists($index, $actual)) {
                \array_push($differences, ...self::diff($value, $actual[$index], "{$path}[{$index}]"));
            }
        }

        return $differences;
    }

    /**
     * @return list<string>
     */
    private static function diffValues(mixed $expected, mixed $actual, string $path): array
    {
        if (\get_debug_type($expected) !== \get_debug_type($actual)) {
            return ["{$path}: type " . \get_debug_type($expected) . ' -> ' . \get_debug_type($actual) . ' (' . self::preview($expected) . ' -> ' . self::preview($actual) . ')'];
        }

        return $expected === $actual ? [] : ["{$path}: " . self::preview($expected) . ' -> ' . self::preview($actual)];
    }

    private static function preview(mixed $value): string
    {
        $encoded = \json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_PRESERVE_ZERO_FRACTION | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        $encoded = $encoded === false ? \get_debug_type($value) : $encoded;

        return \mb_strlen($encoded) > self::PREVIEW_LENGTH ? \mb_substr($encoded, 0, self::PREVIEW_LENGTH) . '…' : $encoded;
    }
}
