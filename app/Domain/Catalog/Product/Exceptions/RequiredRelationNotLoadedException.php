<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Exceptions;

use LogicException;

/**
 * Thrown when code accesses a relation that hasn't been loaded.
 *
 * A programming error (LogicException), not a business rule violation.
 * Indicates the caller should ensure the relation is eager-loaded before use.
 */
final class RequiredRelationNotLoadedException extends LogicException
{
    public function __construct(
        public readonly string $relationName,
        public readonly string $className,
    ) {
        parent::__construct('Required relation not loaded');
    }

    /**
     * @return array{relation: string, class: string}
     */
    public function context(): array
    {
        return [
            'relation' => $this->relationName,
            'class' => $this->className,
        ];
    }
}
