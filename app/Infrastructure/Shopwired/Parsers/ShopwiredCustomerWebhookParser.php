<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\CustomerResponse;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use TypeError;

/**
 * Parses a ShopWired customer webhook payload into a domain Customer object.
 */
final readonly class ShopwiredCustomerWebhookParser implements CustomerWebhookParserInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseCustomer(array $data): Customer
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            return CustomerResponse::from($data['object'])->toDomain();
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired customer webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
