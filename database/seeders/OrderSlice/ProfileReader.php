<?php

declare(strict_types=1);

namespace Database\Seeders\OrderSlice;

use UnexpectedValueException;

/**
 * Reads the orders profile's `distributions` and `shares` sections (shape: `orders-profile-output-check.jq`).
 */
final readonly class ProfileReader
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private array $data,
    ) {}

    /**
     * Rows the mapper returns null for are dropped. The suppressed mass is kept, so draws fall back to synthetic
     * values in the profile's own proportion (COR-299/D9).
     *
     * @template T
     *
     * @param callable(array<mixed>): (T|null) $map
     *
     * @return Weighted<T>
     */
    public function weighted(string $distribution, string $weightKey, callable $map): Weighted
    {
        $section = $this->section('distributions')[$distribution] ?? null;

        if (!\is_array($section) || !\is_array($section['rows'] ?? null)) {
            throw new UnexpectedValueException('Orders profile distribution is missing: ' . $distribution);
        }

        $entries = \array_values(\array_filter(
            \array_map(static fn(mixed $row): ?array => self::entry($row, $weightKey, $map), $section['rows']),
            static fn(?array $entry): bool => $entry !== null,
        ));

        $suppressed = $section['suppressed'] ?? 0;

        return new Weighted($entries, \is_int($suppressed) && $suppressed > 0 ? $suppressed : 0);
    }

    /**
     * @template T
     *
     * @param callable(array<mixed>): (T|null) $map
     *
     * @return array{T, int}|null
     */
    private static function entry(mixed $row, string $weightKey, callable $map): ?array
    {
        if (! \is_array($row)) {
            return null;
        }

        $weight = $row[$weightKey] ?? null;

        if (! \is_int($weight) || $weight <= 0) {
            return null;
        }

        $value = $map($row);

        return $value === null ? null : [$value, $weight];
    }

    /**
     * A share the export could not compute (null) counts as 0.
     */
    public function share(string $key): float
    {
        $share = $this->section('shares')[$key] ?? null;

        if (!\is_array($share) || !\array_key_exists('share', $share)) {
            throw new UnexpectedValueException('Orders profile share is missing: ' . $key);
        }

        return \max(0.0, \min(1.0, self::number($share['share']) ?? 0.0));
    }

    public static function number(mixed $value): ?float
    {
        return match (true) {
            \is_int($value), \is_float($value) => (float) $value,
            \is_string($value) && \is_numeric($value) => (float) $value,
            default => null,
        };
    }

    /**
     * @return array<mixed>
     */
    private function section(string $key): array
    {
        $section = $this->data[$key] ?? null;

        if (!\is_array($section)) {
            throw new UnexpectedValueException('Orders profile section is missing: ' . $key);
        }

        return $section;
    }
}
