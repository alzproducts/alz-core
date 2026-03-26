<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Get a single category by external ID with conditional includes.
 *
 * @see CategoryRepositoryInterface::findCategoryForApi()
 */
final readonly class GetCategoryUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<string> $includes Embed names to load
     *
     * @throws ResourceNotFoundException When no category matches the ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function execute(int $categoryId, array $includes = []): GetCategoryResult
    {
        $this->logger->info('Getting category', [
            'category_id' => $categoryId,
            'includes' => $includes,
        ]);

        $category = $this->categoryRepository->findCategoryForApi(
            IntId::from($categoryId),
            $includes,
        );

        $this->logger->info('Got category', [
            'category_id' => $categoryId,
            'title' => $category->title,
        ]);

        return new GetCategoryResult(
            category: $category,
            includes: $includes,
        );
    }
}
