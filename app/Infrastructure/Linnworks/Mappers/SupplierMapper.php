<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Mappers;

use App\Domain\Inventory\ValueObjects\Supplier;
use App\Infrastructure\Linnworks\Models\SupplierModel;

/**
 * Maps between SupplierModel (Eloquent) and Supplier (Domain).
 */
final class SupplierMapper
{
    /**
     * Convert Eloquent model to Domain Supplier.
     */
    public static function fromModel(SupplierModel $model): Supplier
    {
        return new Supplier(
            pkSupplierId: $model->pk_supplier_id,
            supplierName: $model->supplier_name,
            contactName: $model->contact_name,
            address: $model->address,
            alternativeAddress: $model->alternative_address,
            city: $model->city,
            region: $model->region,
            country: $model->country,
            postCode: $model->post_code,
            telephoneNumber: $model->telephone_number,
            secondaryTelNumber: $model->secondary_tel_number,
            faxNumber: $model->fax_number,
            email: $model->email,
            webPage: $model->web_page,
            currency: $model->currency,
        );
    }

    /**
     * Convert Domain Supplier to Eloquent model attributes.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(Supplier $supplier): array
    {
        return [
            'pk_supplier_id' => $supplier->pkSupplierId,
            'supplier_name' => $supplier->supplierName,
            'contact_name' => $supplier->contactName,
            'address' => $supplier->address,
            'alternative_address' => $supplier->alternativeAddress,
            'city' => $supplier->city,
            'region' => $supplier->region,
            'country' => $supplier->country,
            'post_code' => $supplier->postCode,
            'telephone_number' => $supplier->telephoneNumber,
            'secondary_tel_number' => $supplier->secondaryTelNumber,
            'fax_number' => $supplier->faxNumber,
            'email' => $supplier->email,
            'web_page' => $supplier->webPage,
            'currency' => $supplier->currency,
        ];
    }
}
