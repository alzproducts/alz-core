<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Supplier from the Linnworks master supplier directory.
 *
 * Represents a standalone supplier entity with contact/address details,
 * fetched via the GetSuppliers endpoint. Distinct from StockItemSupplier
 * which represents supplier-to-stock-item junction data.
 *
 * @template-pattern Domain Value Object
 */
final readonly class Supplier
{
    public function __construct(
        public string $pkSupplierId,
        public string $supplierName,
        public ?string $contactName,
        public ?string $address,
        public ?string $alternativeAddress,
        public ?string $city,
        public ?string $region,
        public ?string $country,
        public ?string $postCode,
        public ?string $telephoneNumber,
        public ?string $secondaryTelNumber,
        public ?string $faxNumber,
        public ?string $email,
        public ?string $webPage,
        public ?string $currency,
    ) {
        Assert::notEmpty($pkSupplierId, 'Supplier ID cannot be empty');
        Assert::notEmpty($supplierName, 'Supplier name cannot be empty');
    }
}
