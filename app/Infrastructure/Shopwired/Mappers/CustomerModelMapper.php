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
            address: CustomerAddress::fromNullableFields(
                $model->address_line1,
                $model->address_line2,
                $model->address_line3,
                $model->city,
                $model->province,
                $model->postcode,
            ),
            notes: $model->notes,
            customFields: $model->custom_fields ?? [],
        );
    }

    /**
     * Convert Domain Customer to Eloquent model attributes (full API/bulk path).
     *
     * Note: Does NOT include 'external_id' - that's used as the upsert key
     * and should be handled separately by the repository.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(Customer $customer): array
    {
        return [
            ...self::coreAttributes($customer),
            'custom_fields' => $customer->customFields,
        ];
    }

    /**
     * Convert Domain Customer to Eloquent model attributes for webhook persistence.
     *
     * Only includes embed columns (custom_fields) when they were actually present
     * in the webhook payload, preventing silent overwrites with empty arrays.
     *
     * @param list<string> $presentEmbeds Embed names present in the webhook payload
     *
     * @return array<string, mixed>
     */
    public static function toWebhookAttributes(Customer $customer, array $presentEmbeds): array
    {
        $attributes = self::coreAttributes($customer);

        if (\in_array('custom_fields', $presentEmbeds, true)) {
            $attributes['custom_fields'] = $customer->customFields;
        }

        return $attributes;
    }

    /**
     * Core attributes shared between full and webhook persistence paths.
     *
     * @return array<string, mixed>
     */
    private static function coreAttributes(Customer $customer): array
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
            'shopwired_created_at' => $customer->createdAt,
        ];
    }

}
