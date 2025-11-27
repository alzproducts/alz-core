<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

/**
 * Interface for Infrastructure DTOs that convert to Domain objects.
 *
 * Implemented by API response DTOs across all external service clients
 * to enable automatic domain conversion in response parser utilities.
 *
 * @internal For use within Infrastructure layer only
 */
interface DomainConvertible
{
    /**
     * Convert this DTO to its corresponding Domain object.
     *
     * @return object The domain value object or entity
     */
    public function toDomain(): object;
}
