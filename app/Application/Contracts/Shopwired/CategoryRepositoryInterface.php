<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\ValueObjects\Category;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Repository for ShopWired category persistence.
 *
 * @extends RepositoryWriteInterface<Category>
 */
interface CategoryRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get all categories, ordered by sort_order.
     *
     * @return list<Category>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findAll(): array;

    /**
     * Find a category by its ShopWired external ID.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findByExternalId(int $externalId): ?Category;

    /**
     * Upsert a category from webhook data.
     *
     * When $presentEmbeds is non-empty, only persists embed columns that were
     * actually present in the webhook payload (prevents overwriting with empty arrays).
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveFromWebhook(Category $category, array $presentEmbeds = []): void;

    /**
     * Delete a category by its ShopWired external ID.
     *
     * Used by `category.deleted` webhook.
     *
     * @throws ResourceNotFoundException When no category found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;
}
