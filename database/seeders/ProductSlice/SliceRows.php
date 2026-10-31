<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice;

use OutOfBoundsException;

/**
 * Generated rows per fully-qualified table name, in insert order.
 */
final readonly class SliceRows
{
    /**
     * @param array<string, list<array<string, mixed>>> $tables
     */
    public function __construct(
        public array $tables,
    ) {}

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return \array_keys($this->tables);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $table): array
    {
        if (!\array_key_exists($table, $this->tables)) {
            throw new OutOfBoundsException('Slice has no table ' . $table);
        }

        return $this->tables[$table];
    }

    public function count(string $table): int
    {
        return \count($this->rows($table));
    }
}
