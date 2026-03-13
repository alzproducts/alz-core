<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Parsers;

use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Application\Shopwired\DTOs\WebhookCustomerResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Shopwired\Responses\CustomerWebhookResponse;
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
    public function parseCustomer(array $data): WebhookCustomerResultDTO
    {
        try {
            /** @var array{object: array<string, mixed>} $data */
            $response = CustomerWebhookResponse::from($data['object']);

            return new WebhookCustomerResultDTO(
                customer: $response->toDomain(),
                presentEmbeds: $response->presentEmbeds(),
            );
        } catch (TypeError|CannotCreateData $e) {
            Log::error('ShopWired customer webhook payload type mismatch', ['error' => $e->getMessage()]);
            throw new InvalidApiResponseException('ShopWired', previous: $e);
        }
    }
}
