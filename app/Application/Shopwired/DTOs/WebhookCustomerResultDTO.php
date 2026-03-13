<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Domain\Customer\ValueObjects\Customer;

/**
 * Result from parsing a customer webhook payload.
 *
 * Carries the parsed customer alongside which embed fields were present
 * in the webhook payload, so downstream consumers can conditionally
 * persist only the columns that have real data.
 */
final readonly class WebhookCustomerResultDTO
{
    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     */
    public function __construct(
        public Customer $customer,
        public array $presentEmbeds,
    ) {}
}
