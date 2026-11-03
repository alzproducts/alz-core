<?php

declare(strict_types=1);

namespace Database\Factories\ProductSlice;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplies one slice table's row shape. The slice generator owns structure (counts, IDs, links, traps)
 * and passes it as overrides; the definition fills every other column, so every row has the same keys.
 *
 * @template TModel of Model
 *
 * @extends Factory<TModel>
 */
abstract class AbstractSliceRowFactory extends Factory
{
    public const string BASE_TIMESTAMP = '2026-01-01 00:00:00';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    final public function row(array $overrides = []): array
    {
        $row = [];

        foreach ($this->raw($overrides) as $column => $value) {
            $row[(string) $column] = $value;
        }

        return $row;
    }

    /**
     * Reads a resolved attribute inside a lazy attribute closure, which Laravel passes the untyped attribute array.
     *
     * @param array<array-key, mixed> $attributes
     */
    final protected static function stringAttribute(array $attributes, string $key): string
    {
        $value = $attributes[$key] ?? null;

        return \is_scalar($value) ? (string) $value : '';
    }

    final protected function phrase(int $words): string
    {
        $phrase = [];

        for ($n = 0; $n < $words; ++$n) {
            $phrase[] = $this->faker->word();
        }

        return \implode(' ', $phrase);
    }
}
