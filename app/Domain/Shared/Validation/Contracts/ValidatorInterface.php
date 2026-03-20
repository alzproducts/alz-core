<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation\Contracts;

/**
 * Shared interface for all validators.
 *
 * Parameterless validate() is possible because domain objects are injected
 * via the constructor, making validators short-lived objects constructed
 * with specific data rather than stateless services.
 *
 * PHP supports covariant return types, so concrete validators can declare
 * more specific return types (e.g., validate(): SkuBelongsToProductResult)
 * while satisfying this interface.
 */
interface ValidatorInterface
{
    public function validate(): DescribableValidationResultInterface;
}
