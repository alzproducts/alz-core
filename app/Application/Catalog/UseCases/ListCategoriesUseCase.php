<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\Category\ValueObjects\CategoryView;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * List categories with optional includes and active filtering.
 *
 * @see CategoryRepositoryInterface::paginate()
 */
final readonly class ListCategoriesUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $includes Embed names to load
     *
     * @return PaginatedListDTO<CategoryView>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function execute(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedListDTO
    {
        $this->logger->info('Listing categories', [
            'page' => $page,
            'per_page' => $perPage,
            'includes' => $includes,
            'include_inactive' => $includeInactive,
        ]);

        $result = $this->categoryRepository->paginate($perPage, $page, $includes, $includeInactive);

        $this->logger->info('Listed categories', [
            'total' => $result->total,
            'returned' => \count($result->items),
        ]);

        return $result;
    }
}
