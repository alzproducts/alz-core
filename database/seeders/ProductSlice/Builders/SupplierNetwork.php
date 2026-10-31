<?php

declare(strict_types=1);

namespace Database\Seeders\ProductSlice\Builders;

final readonly class SupplierNetwork
{
    /**
     * @param list<array<string, mixed>> $supplierRows
     * @param list<array<string, mixed>> $linkRows
     * @param list<array{id: string, name: string}> $linkedSuppliers
     */
    public function __construct(
        public array $supplierRows,
        public array $linkRows,
        public array $linkedSuppliers,
    ) {}
}
