<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks\Mappers;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksCustomerMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QuickBooksOnline\API\Data\IPPEmailAddress;
use QuickBooksOnline\API\Data\IPPPhysicalAddress;
use QuickBooksOnline\API\Data\IPPTelephoneNumber;

#[CoversClass(QuickBooksCustomerMapper::class)]
final class QuickBooksCustomerMapperTest extends TestCase
{
    #[Test]
    public function it_maps_every_present_field(): void
    {
        $customer = QuickBooksCustomerMapper::toEntity(new CustomerDraftDTO(
            displayName: 'Jo Bloggs',
            givenName: 'Jo',
            familyName: 'Bloggs',
            companyName: 'Bloggs Ltd',
            email: ' jo@example.com ',
            phone: '01234 567890',
            billingAddress: self::address('1 High St', 'Flat 2', 'Leeds', 'LS1 1AA', 'GB'),
        ));

        $this->assertSame('Jo Bloggs', $customer->DisplayName);
        $this->assertSame('Jo', $customer->GivenName);
        $this->assertSame('Bloggs', $customer->FamilyName);
        $this->assertSame('Bloggs Ltd', $customer->CompanyName);
        $this->assertInstanceOf(IPPEmailAddress::class, $customer->PrimaryEmailAddr);
        $this->assertSame('jo@example.com', $customer->PrimaryEmailAddr->Address);
        $this->assertInstanceOf(IPPTelephoneNumber::class, $customer->PrimaryPhone);
        $this->assertSame('01234 567890', $customer->PrimaryPhone->FreeFormNumber);
        $this->assertInstanceOf(IPPPhysicalAddress::class, $customer->BillAddr);
        $this->assertSame(['1 High St', 'Flat 2', 'Leeds', 'LS1 1AA', 'GB'], [
            $customer->BillAddr->Line1,
            $customer->BillAddr->Line2,
            $customer->BillAddr->City,
            $customer->BillAddr->PostalCode,
            $customer->BillAddr->Country,
        ]);
    }

    #[Test]
    public function blank_fields_are_left_unset(): void
    {
        $customer = QuickBooksCustomerMapper::toEntity(new CustomerDraftDTO(
            displayName: 'Jo Bloggs',
            givenName: ' ',
            familyName: '',
            companyName: '',
            email: '  ',
            phone: '',
            billingAddress: self::address('', ' ', '', '', ''),
        ));

        $this->assertSame('Jo Bloggs', $customer->DisplayName);
        $this->assertNull($customer->GivenName);
        $this->assertNull($customer->FamilyName);
        $this->assertNull($customer->CompanyName);
        $this->assertNull($customer->PrimaryEmailAddr);
        $this->assertNull($customer->PrimaryPhone);
        $this->assertNull($customer->BillAddr);
    }

    #[Test]
    public function a_partial_address_keeps_only_its_present_lines(): void
    {
        $customer = QuickBooksCustomerMapper::toEntity(new CustomerDraftDTO('Jo Bloggs', '', '', '', '', '', self::address('', '', '', 'LS1 1AA', '')));

        $this->assertInstanceOf(IPPPhysicalAddress::class, $customer->BillAddr);
        $this->assertSame('LS1 1AA', $customer->BillAddr->PostalCode);
        $this->assertNull($customer->BillAddr->Line1);
    }

    private static function address(string $line1, string $line2, string $city, string $postcode, string $country): StructuredAddressDTO
    {
        return StructuredAddressDTO::fromContact(new OrderContactDTO('Jo Bloggs', '', $line1, $line2, '', $city, $postcode, $country));
    }
}
