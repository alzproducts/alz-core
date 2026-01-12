<?php

declare(strict_types=1);

namespace App\Infrastructure\Contracts;

/**
 * Interface for Eloquent models that map to/from Domain objects.
 *
 * Extends DomainConvertibleInterface to provide bidirectional conversion
 * between Eloquent models and Domain entities. Use this on any Eloquent model
 * that needs to be persisted from or hydrated to Domain objects via repositories.
 *
 * For simple models: implement methods directly with inline conversion logic.
 * For complex models: delegate to a dedicated mapper class.
 *
 * @template TDomain of object
 *
 * @internal For use within Infrastructure layer only
 */
interface EloquentDomainMappableInterface extends DomainConvertibleInterface
{
    /**
     * Convert a Domain object to Eloquent model attributes.
     *
     * Returns an array of attributes suitable for create/update operations.
     * Does not include the primary key or timestamps (handled by Eloquent).
     *
     * @param TDomain $entity The domain entity to convert
     *
     * @return array<string, mixed> Attributes for Eloquent create/update
     */
    public static function fromDomainAttributes(object $entity): array;
}
