<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use LogicException;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Facades\Customer;

/**
 * A customer draft as a QBO create payload. Blank fields are left unset: QBO rejects an empty email or phone.
 * Built through the SDK facade, as its entity constructors and properties carry PHPDoc types that do not exist.
 */
final readonly class QuickBooksCustomerMapper
{
    public static function toEntity(CustomerDraftDTO $draft): IPPCustomer
    {
        $customer = Customer::create(self::payload($draft));

        if (!$customer instanceof IPPCustomer) {
            throw new LogicException('The SDK facade did not build a customer');
        }

        return $customer;
    }

    /**
     * @return array<string, array<string, string>|string>
     */
    private static function payload(CustomerDraftDTO $draft): array
    {
        $email = self::nonBlank($draft->email);
        $phone = self::nonBlank($draft->phone);
        $address = self::address($draft->billingAddress);

        return self::present([
            'DisplayName' => $draft->displayName,
            'GivenName' => self::nonBlank($draft->givenName),
            'FamilyName' => self::nonBlank($draft->familyName),
            'CompanyName' => self::nonBlank($draft->companyName),
            'PrimaryEmailAddr' => $email === null ? null : ['Address' => $email],
            'PrimaryPhone' => $phone === null ? null : ['FreeFormNumber' => $phone],
            'BillAddr' => $address === [] ? null : $address,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private static function address(StructuredAddressDTO $address): array
    {
        return self::present([
            'Line1' => self::nonBlank($address->line1),
            'Line2' => self::nonBlank($address->line2),
            'City' => self::nonBlank($address->city),
            'PostalCode' => self::nonBlank($address->postcode),
            'Country' => self::nonBlank($address->country),
        ]);
    }

    /**
     * @template T of array<string, string>|string
     *
     * @param array<string, T|null> $fields
     *
     * @return array<string, T>
     */
    private static function present(array $fields): array
    {
        return \array_filter($fields, static fn(mixed $value): bool => $value !== null);
    }

    private static function nonBlank(string $value): ?string
    {
        $trimmed = \mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
