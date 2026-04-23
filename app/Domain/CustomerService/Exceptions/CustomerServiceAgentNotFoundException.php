<?php

/** @noinspection PhpClassNamingConventionInspection */

declare(strict_types=1);

namespace App\Domain\CustomerService\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

/**
 * Thrown when an authenticated user has no matching support agent account.
 *
 * This indicates the authenticated user doesn't have a corresponding
 * agent account in the customer service platform.
 *
 * Use cases:
 * - User exists in auth system but not in customer service platform
 * - Email mismatch between systems
 * - New employee not yet provisioned
 */
final class CustomerServiceAgentNotFoundException extends DomainException
{
    public function __construct(
        public readonly string $email,
    ) {
        parent::__construct('Customer service agent not found');
    }

    #[Override]
    public function context(): array
    {
        return ['email' => $this->email];
    }
}
