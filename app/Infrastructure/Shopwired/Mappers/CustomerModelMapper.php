<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Mappers;

use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Customer\ValueObjects\CustomerAddress;
use App\Infrastructure\Shopwired\Models\CustomerModel;

/**
 * Maps between CustomerModel (Eloquent) and Customer (Domain).
 *
 * Handles transformations including:
 * - Snake_case ↔ camelCase property mapping
 * - CustomerAddress value object reconstruction
 * - Timestamp conversions
 */
final class CustomerModelMapper
{
    /**
     * Convert Eloquent model to Domain Customer.
     */
    public static function fromModel(CustomerModel $model): Customer
    {
        return new Customer(
            id: $model->external_id,
            createdAt: $model->shopwired_created_at->toDateTimeImmutable(),
            email: $model->email,
            firstName: $model->first_name,
            lastName: $model->last_name,
            companyName: $model->company_name,
            isTrade: $model->is_trade,
            isActive: $model->is_active,
            isCreditEnabled: $model->is_credit_enabled,
            phone: $model->phone,
            mobilePhone: $model->mobile_phone,
            acceptsMarketing: $model->accepts_marketing,
            address: self::buildAddress($model),
            notes: $model->notes,
            customFields: $model->custom_fields ?? [],
        );
    }

    /**
     * Convert Domain Customer to Eloquent model attributes.
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(Customer $customer): array
    {
        return [
            'email' => $customer->email,
            'first_name' => $customer->firstName,
            'last_name' => $customer->lastName,
            'company_name' => $customer->companyName,
            'is_trade' => $customer->isTrade,
            'is_active' => $customer->isActive,
            'is_credit_enabled' => $customer->isCreditEnabled,
            'phone' => $customer->phone,
            'mobile_phone' => $customer->mobilePhone,
            'accepts_marketing' => $customer->acceptsMarketing,
            'address_line1' => $customer->address?->line1,
            'address_line2' => $customer->address?->line2,
            'address_line3' => $customer->address?->line3,
            'city' => $customer->address?->city,
            'province' => $customer->address?->province,
            'postcode' => $customer->address?->postcode,
            'notes' => $customer->notes,
            'custom_fields' => $customer->customFields,
            'shopwired_created_at' => $customer->createdAt,
        ];
    }

    /**
     * Build CustomerAddress from flat model columns.
     */
    private static function buildAddress(CustomerModel $model): ?CustomerAddress
    {
        // Return null if all address fields are empty
        if ($model->address_line1 === null
            && $model->address_line2 === null
            && $model->address_line3 === null
            && $model->city === null
            && $model->province === null
            && $model->postcode === null
        ) {
            return null;
        }

        return new CustomerAddress(
            line1: $model->address_line1,
            line2: $model->address_line2,
            line3: $model->address_line3,
            city: $model->city,
            province: $model->province,
            postcode: $model->postcode,
        );
    }
}
