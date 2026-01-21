<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

/**
 * Interface for child DTOs that require a parent identifier to convert to Domain.
 *
 * Used by nested API response DTOs (e.g., ProductVariationResponse) that need
 * context from their parent (e.g., productExternalId) to create the domain object.
 *
 * @internal For use within Infrastructure layer only
 */
interface DomainConvertibleChildInterface
{
    /**
     * Convert this DTO to its corresponding Domain object.
     *
     * @param int|string $parentId The parent entity's identifier
     *
     * @return object The domain value object or entity
     */
    public function toDomain(int|string $parentId): object;
}
