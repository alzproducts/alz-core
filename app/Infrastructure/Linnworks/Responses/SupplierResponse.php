<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\ValueObjects\Supplier;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Linnworks supplier directory API response DTO.
 *
 * Maps fields from the GetSuppliers endpoint (master supplier directory).
 * Distinct from StockItemSupplierResponse which maps supplier-to-stock-item junction data.
 *
 * @see https://apps.linnworks.net/Api/Method/Inventory-GetSuppliers
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class SupplierResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        #[MapInputName('pkSupplierID')]
        public readonly string $pkSupplierId,
        public readonly string $supplierName,
        public readonly ?string $contactName,
        public readonly ?string $address,
        public readonly ?string $alternativeAddress,
        public readonly ?string $city,
        public readonly ?string $region,
        public readonly ?string $country,
        public readonly ?string $postCode,
        public readonly ?string $telephoneNumber,
        public readonly ?string $secondaryTelNumber,
        public readonly ?string $faxNumber,
        public readonly ?string $email,
        public readonly ?string $webPage,
        public readonly ?string $currency,
    ) {}

    public function toDomain(): Supplier
    {
        return new Supplier(
            pkSupplierId: $this->pkSupplierId,
            supplierName: $this->supplierName,
            contactName: $this->contactName,
            address: $this->address,
            alternativeAddress: $this->alternativeAddress,
            city: $this->city,
            region: $this->region,
            country: $this->country,
            postCode: $this->postCode,
            telephoneNumber: $this->telephoneNumber,
            secondaryTelNumber: $this->secondaryTelNumber,
            faxNumber: $this->faxNumber,
            email: $this->email,
            webPage: $this->webPage,
            currency: $this->currency,
        );
    }
}
