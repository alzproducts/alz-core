<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;
use Spatie\LaravelData\Optional;

/**
 * ShopWired Webhook Response: Customer.
 *
 * Webhook payloads don't include embed data (wishlists, customFields).
 * Uses Spatie Optional for embed fields so missing data is detected
 * rather than silently defaulting to empty arrays.
 *
 * @see CustomerResponse for the strict API client DTO (all embeds required)
 * @see https://shopwired.readme.io/reference/listcustomers
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CustomerWebhookResponse extends Data
{
    /**
     * @param list<CustomerWishlistResponse> $wishlists
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        // Core fields — always present in webhooks
        public readonly int $id,
        public readonly string $createdAt,
        public readonly ?int $tradeGroupId,
        public readonly bool $adminCreated,
        public readonly bool $autoCreated,
        public readonly string $email,
        public readonly string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $companyName,
        public readonly bool $trade,
        public readonly bool $active,
        public readonly ?string $phone,
        public readonly ?string $mobilePhone,
        public readonly ?string $website,
        public readonly ?string $vatNumber,
        public readonly bool $acceptsMarketing,
        #[MapInputName('address_line_1')]
        public readonly ?string $addressLine1,
        #[MapInputName('address_line_2')]
        public readonly ?string $addressLine2,
        #[MapInputName('address_line_3')]
        public readonly ?string $addressLine3,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $postcode,
        public readonly int $rewardPoints,
        public readonly ?string $notes,

        // Optional fields (trade-specific, not returned for regular customers)
        public readonly ?float $discount = null,
        public readonly ?float $costPriceMultiplier = null,
        #[MapInputName('credit')]
        public readonly ?bool $creditEnabled = null,

        // Embed fields — Optional (may be absent from webhooks)
        #[DataCollectionOf(CustomerWishlistResponse::class)]
        public readonly array|Optional $wishlists = new Optional(),
        public readonly array|Optional $customFields = new Optional(),
    ) {}

    /**
     * Returns the list of embed names that were actually present in the payload.
     *
     * @return list<string> Embed names (matching ShopWired API embed names)
     */
    public function presentEmbeds(): array
    {
        $embeds = [];

        if (! $this->wishlists instanceof Optional) {
            $embeds[] = 'wishlists';
        }

        if (! $this->customFields instanceof Optional) {
            $embeds[] = 'custom_fields';
        }

        return $embeds;
    }

    /**
     * Convert to Domain Value Object.
     *
     * Optional embed fields are coalesced to empty arrays for the domain layer,
     * which always expects concrete values. Use presentEmbeds() to determine
     * which fields should be persisted.
     *
     * @throws InvalidApiResponseException When date format is invalid
     */
    public function toDomain(): DomainCustomer
    {
        try {
            $createdAt = new DateTimeImmutable($this->createdAt);
        } catch (DateMalformedStringException $e) {
            throw new InvalidApiResponseException(
                serviceName: 'Shopwired',
                message: "Invalid date format in customer {$this->id}",
                previous: $e,
            );
        }

        return new DomainCustomer(
            id: $this->id,
            createdAt: $createdAt,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName ?? '',
            companyName: $this->companyName,
            isTrade: $this->trade,
            isActive: $this->active,
            isCreditEnabled: $this->creditEnabled,
            phone: $this->phone,
            mobilePhone: $this->mobilePhone,
            acceptsMarketing: $this->acceptsMarketing,
            address: CustomerAddress::fromNullableFields(
                $this->addressLine1,
                $this->addressLine2,
                $this->addressLine3,
                $this->city,
                $this->province,
                $this->postcode,
            ),
            notes: $this->notes,
            customFields: $this->customFields instanceof Optional ? [] : $this->customFields,
        );
    }

}
