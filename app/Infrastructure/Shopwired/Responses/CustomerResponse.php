<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Responses;

use App\Domain\Customer\ValueObjects\Customer as DomainCustomer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use DateMalformedStringException;
use DateTimeImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * ShopWired API Response: Customer.
 *
 * Infrastructure DTO for parsing customer API responses.
 * Handles snake_case → camelCase mapping automatically.
 *
 * @see https://shopwired.readme.io/reference/listcustomers
 */
#[MapInputName(SnakeCaseMapper::class)]
final class CustomerResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<CustomerWishlistResponse> $wishlists Customer wishlists (embedded, not converted to domain)
     * @param array<string, mixed> $customFields Custom field key-value pairs
     */
    public function __construct(
        // Identifiers
        public readonly int $id,
        public readonly string $createdAt,

        // Infrastructure-only fields (not in Domain)
        public readonly ?int $tradeGroupId,
        public readonly bool $adminCreated,
        public readonly bool $autoCreated,

        // Identity
        public readonly string $email,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly ?string $companyName,

        // Classification
        public readonly bool $trade,
        public readonly bool $active,

        // Contact
        public readonly ?string $phone,
        public readonly ?string $mobilePhone,
        public readonly ?string $website,
        public readonly ?string $vatNumber,
        public readonly bool $acceptsMarketing,

        // Address (flat fields from API)
        // Note: Explicit mapping needed for numeric suffixes (SnakeCaseMapper doesn't handle them)
        #[MapInputName('address_line_1')]
        public readonly ?string $addressLine1,
        #[MapInputName('address_line_2')]
        public readonly ?string $addressLine2,
        #[MapInputName('address_line_3')]
        public readonly ?string $addressLine3,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $postcode,

        // Loyalty
        public readonly int $rewardPoints,

        // Notes
        public readonly ?string $notes,

        // Optional fields (trade-specific, not returned for regular customers)
        public readonly ?float $discount = null,
        public readonly ?float $costPriceMultiplier = null,
        #[MapInputName('credit')]
        public readonly ?bool $creditEnabled = null,

        // Embedded objects (optional, require embed param)
        // Note: country/state return only IDs from API - ignored until needed
        #[DataCollectionOf(CustomerWishlistResponse::class)]
        public readonly array $wishlists = [],
        public readonly array $customFields = [],
    ) {}

    /**
     * Convert to Domain Value Object.
     *
     * Note: wishlists are intentionally NOT converted to domain
     * as they are ShopWired-specific with no business logic use.
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
            lastName: $this->lastName,
            companyName: $this->companyName,
            isTrade: $this->trade,
            isActive: $this->active,
            isCreditEnabled: $this->creditEnabled,
            phone: $this->phone,
            mobilePhone: $this->mobilePhone,
            acceptsMarketing: $this->acceptsMarketing,
            address: $this->buildAddress(),
            notes: $this->notes,
            customFields: $this->customFields,
        );
    }

    /**
     * Build CustomerAddress from flat API fields.
     */
    private function buildAddress(): ?CustomerAddress
    {
        // Return null if no address fields are set
        if (($this->addressLine1 === null)
            && ($this->addressLine2 === null)
            && ($this->addressLine3 === null)
            && ($this->city === null)
            && ($this->province === null)
            && ($this->postcode === null)
        ) {
            return null;
        }

        return new CustomerAddress(
            line1: $this->addressLine1,
            line2: $this->addressLine2,
            line3: $this->addressLine3,
            city: $this->city,
            province: $this->province,
            postcode: $this->postcode,
        );
    }
}
