<?php

declare(strict_types=1);

namespace App\Presentation\Http\Requests;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\Customer\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates contact form submissions from the frontend.
 *
 * Uses derived enum validation - rules auto-sync with enum changes.
 * Returns 422 with field errors for invalid data.
 */
final class ContactFormRequest extends FormRequest
{
    public function authorize(): true
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Core form fields
            'form.name' => ['required', 'string', 'max:255'],
            'form.email' => ['required', 'string', 'email', 'max:255'],
            'form.reason' => ['required', 'string', Rule::in($this->getReasonLabels())],
            'form.message' => ['required', 'string'],
            'form.phone' => ['nullable', 'string', 'max:50'],
            'form.customer_type' => ['nullable', 'string', Rule::in($this->getCustomerTypeValues())],
            'form.order_number' => ['nullable', 'string', 'max:20'],
            'form.delivery_postcode' => ['nullable', 'string', 'max:20'],
            'form.quantity' => ['nullable', 'integer', 'min:1', 'max:999'],

            // Selected product (optional, stored as JSONB - no length limits)
            'product' => ['nullable', 'array'],
            'product.sku' => ['required_with:product', 'string'],
            'product.title' => ['nullable', 'string'],
            'product.price' => ['nullable', 'string'],
            'product.url' => ['nullable', 'string'],
            'product.manual_url' => ['nullable', 'string'],
            'product.source' => ['nullable', 'string', Rule::in($this->getProductSourceValues())],

            // Consent status
            'consent.marketing' => ['required', 'boolean'],
            'consent.statistics' => ['required', 'boolean'],
            'consent.preferences' => ['required', 'boolean'],
            'consent.has_responded' => ['required', 'boolean'],

            // Marketing attribution (all optional)
            'attribution.gclid' => ['nullable', 'string', 'max:255'],
            'attribution.utm_source' => ['nullable', 'string', 'max:255'],
            'attribution.utm_medium' => ['nullable', 'string', 'max:255'],
            'attribution.utm_campaign' => ['nullable', 'string', 'max:255'],
            'attribution.utm_content' => ['nullable', 'string', 'max:255'],
            'attribution.utm_term' => ['nullable', 'string', 'max:255'],

            // Page/session context (TEXT columns - no max length)
            'context.page_url' => ['nullable', 'string'],
            'context.referrer_url' => ['nullable', 'string'],
            'context.user_agent' => ['nullable', 'string'],
            'context.timestamp' => ['required', 'string', 'date'],

            // Spam protection (honeypot) - no validation, just accept
            'spam.honeypot_value' => ['nullable', 'string'],

            // User identification
            'user.customer_id' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return list<string>
     */
    private function getReasonLabels(): array
    {
        return \array_map(
            static fn(ContactReason $reason): string => $reason->label(),
            ContactReason::cases(),
        );
    }

    /**
     * @return list<string>
     */
    private function getCustomerTypeValues(): array
    {
        $types = \array_map(
            static fn(CustomerType $type): string => $type->value,
            CustomerType::cases(),
        );

        // Frontend sends 'prefer_not_to_say' which maps to null in domain
        $types[] = 'prefer_not_to_say';

        return $types;
    }

    /**
     * @return list<string>
     */
    private function getProductSourceValues(): array
    {
        return \array_map(
            static fn(ProductSource $source): string => $source->value,
            ProductSource::cases(),
        );
    }
}
