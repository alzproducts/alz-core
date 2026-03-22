<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Clients;

use App\Application\Contracts\Shopwired\CustomerFieldUpdateClientInterface;
use App\Domain\Customer\Enums\CustomerUpdatableField;
use App\Domain\Customer\ValueObjects\CustomerFieldUpdate;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Infrastructure\Shopwired\Contracts\ShopwiredTransportInterface;

/**
 * Simple field updates for ShopWired customers.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class CustomerFieldUpdateClient implements CustomerFieldUpdateClientInterface
{
    private const string ENDPOINT = 'customers';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotAvailableException When customer not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $customerId, CustomerFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $customerId, $payload);
    }

    private static function mapField(CustomerUpdatableField $field): string
    {
        return match ($field) {
            CustomerUpdatableField::FirstName => 'firstName',
        };
    }
}
