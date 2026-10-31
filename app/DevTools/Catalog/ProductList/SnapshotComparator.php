<?php

declare(strict_types=1);

namespace App\DevTools\Catalog\ProductList;

use JsonException;
use RuntimeException;

/**
 * Compares two captures file by file and field by field.
 */
final readonly class SnapshotComparator
{
    public function __construct(
        private SnapshotStore $store,
    ) {}

    /**
     * @return list<string> Every difference, each prefixed with its file name; empty when the captures match
     *
     * @throws RuntimeException When the reference capture does not exist
     * @throws JsonException When a captured file is not valid JSON
     */
    public function compare(string $reference, string $candidate): array
    {
        if (! $this->store->exists($reference)) {
            throw new RuntimeException("No capture labelled {$reference} exists.");
        }

        $referenceNames = $this->store->names($reference);
        $candidateNames = $this->store->names($candidate);
        $differences = [
            ...\array_map(static fn(string $name): string => "{$name}: missing from {$candidate}", \array_values(\array_diff($referenceNames, $candidateNames))),
            ...\array_map(static fn(string $name): string => "{$name}: not in {$reference}", \array_values(\array_diff($candidateNames, $referenceNames))),
        ];

        foreach (\array_intersect($referenceNames, $candidateNames) as $name) {
            \array_push($differences, ...$this->compareFile($name, $reference, $candidate));
        }

        return $differences;
    }

    /**
     * @return list<string>
     *
     * @throws RuntimeException
     * @throws JsonException
     */
    private function compareFile(string $name, string $reference, string $candidate): array
    {
        $expected = $this->store->read($reference, $name);
        $actual = $this->store->read($candidate, $name);

        $differences = \array_map(
            static fn(string $difference): string => "{$name}: {$difference}",
            JsonDiff::diff(\json_decode($expected, flags: \JSON_THROW_ON_ERROR), \json_decode($actual, flags: \JSON_THROW_ON_ERROR)),
        );

        if ($differences === [] && $expected !== $actual) {
            return ["{$name}: bytes differ although the decoded JSON is equal"];
        }

        return $differences;
    }
}
