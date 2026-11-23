<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

/**
 * An active QBO customer a document can post against.
 */
final readonly class CustomerMatchDTO
{
    /**
     * @param non-empty-string      $id
     * @param non-empty-string|null $salesTermRef null when the customer has no default terms
     * @param string|null           $currency     null when the record has none, '' when QBO's value cannot be read
     */
    public function __construct(
        public string $id,
        public string $displayName,
        public ?string $salesTermRef,
        public ?string $currency,
    ) {}
}
