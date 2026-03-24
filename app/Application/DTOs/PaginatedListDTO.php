<?php

declare(strict_types=1);

namespace App\Application\DTOs;

/**
 * Framework-free paginated result.
 *
 * Decouples Application layer from Illuminate\Pagination (Deptrac boundary).
 * Reconstructed as LengthAwarePaginator in Presentation for Resource compatibility.
 *
 * @template-covariant T
 */
final readonly class PaginatedListDTO
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}
}
