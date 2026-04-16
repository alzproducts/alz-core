<?php

declare(strict_types=1);

namespace App\Infrastructure\Concerns;

/**
 * Structured-log context for enum-resolution fallbacks.
 *
 * Bundles the entity's external ID and the source column name so
 * MapperHelperTrait::buildEnum() can emit a structured log entry
 * without inflating its parameter count.
 */
final readonly class EnumLogContext
{
    public function __construct(
        public int $externalId,
        public string $fieldName,
    ) {}
}
