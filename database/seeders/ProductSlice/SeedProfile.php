<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

use JsonException;
use UnexpectedValueException;

/**
 * The committed production shape (counts and distributions) the generator reproduces.
 */
final readonly class SeedProfile
{
    public const string DEFAULT_PATH = __DIR__ . '/../../seed-profile/products-profile.json';

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        private array $data,
    ) {}

    /**
     * @throws JsonException
     */
    public static function fromFile(string $path = self::DEFAULT_PATH): self
    {
        $json = \file_get_contents($path);

        if ($json === false) {
            throw new UnexpectedValueException('Seed profile file is unreadable');
        }

        return self::fromJson($json);
    }

    /**
     * @throws JsonException
     */
    public static function fromJson(string $json): self
    {
        $data = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($data)) {
            throw new UnexpectedValueException('Seed profile must be a JSON object');
        }

        return new self(self::stringKeyed($data));
    }

    /**
     * @param string $path dot-separated key path, e.g. `counts.products`
     */
    public function int(string $path): int
    {
        $value = $this->at($path);

        if (!\is_int($value)) {
            throw new UnexpectedValueException('Seed profile value is not an integer: ' . $path);
        }

        return $value;
    }

    /**
     * A distribution keyed by bucket label (e.g. `"36" => 50`), in profile order. PHP turns numeric labels into int keys.
     *
     * @return array<array-key, int>
     */
    public function histogram(string $path): array
    {
        $value = $this->at($path);

        if (!\is_array($value)) {
            throw new UnexpectedValueException('Seed profile value is not a distribution: ' . $path);
        }

        $histogram = [];

        foreach ($value as $bucket => $count) {
            if (!\is_int($count)) {
                throw new UnexpectedValueException('Seed profile distribution holds a non-integer count: ' . $path);
            }

            $histogram[$bucket] = $count;
        }

        return $histogram;
    }

    /**
     * A distribution whose buckets are integers (e.g. variations per product).
     *
     * @return array<int, int>
     */
    public function intHistogram(string $path): array
    {
        $histogram = [];

        foreach ($this->histogram($path) as $bucket => $count) {
            if (!\is_int($bucket)) {
                throw new UnexpectedValueException('Seed profile distribution bucket is not an integer: ' . $path);
            }

            $histogram[$bucket] = $count;
        }

        \ksort($histogram);

        return $histogram;
    }

    private function at(string $path): mixed
    {
        $node = $this->data;

        foreach (\explode('.', $path) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                throw new UnexpectedValueException('Seed profile key is missing: ' . $path);
            }

            $node = $node[$segment];
        }

        return $node;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $keyed = [];

        foreach ($data as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }
}
