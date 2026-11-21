<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\OrderSync\ValueObjects;

/**
 * Per-run labels that keep verify runs apart in a shared sandbox company: a DocNumber prefix and a customer DisplayName suffix.
 * An empty label leaves its field unchanged.
 */
final readonly class DocumentRunLabels
{
    public function __construct(
        public string $docNumberPrefix,
        public string $customerNameSuffix,
    ) {}

    public static function none(): self
    {
        return new self('', '');
    }
}
