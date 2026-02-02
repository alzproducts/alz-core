<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Presentation\Http\Requests\ContactFormRequest;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Transforms a validated ContactFormRequest into Domain objects.
 *
 * Handles:
 * - Enum mapping (reason labels → enum, customer type with 'prefer_not_to_say' → null)
 * - Server-side context (IP address from request)
 * - Optional product construction
 *
 * Note: This class trusts that FormRequest validation has already passed.
 * Type annotations use @var to satisfy PHPStan since validation guarantees types.
 */
final readonly class ContactSubmissionFactory
{
    /**
     * @throws InvalidEnumValueException When enum values don't match expected values
     */
    public function fromRequest(ContactFormRequest $request): ContactSubmission
    {
        return new ContactSubmission(
            form: $this->buildFormData($request),
            consent: $this->buildConsent($request),
            attribution: $this->buildAttribution($request),
            context: $this->buildContext($request),
            product: $this->buildProduct($request),
            shopwiredCustomerId: self::stringOrNull($request->input('user.customer_id')),
        );
    }

    /**
     * @throws InvalidEnumValueException When reason label doesn't match any ContactReason
     */
    private function buildFormData(ContactFormRequest $request): ContactFormData
    {
        /** @var string $name Validated by FormRequest */
        $name = $request->input('form.name');

        /** @var string $email Validated by FormRequest */
        $email = $request->input('form.email');

        /** @var string $reason Validated by FormRequest */
        $reason = $request->input('form.reason');

        /** @var string $message Validated by FormRequest */
        $message = $request->input('form.message');

        return new ContactFormData(
            name: $name,
            email: $email,
            reason: ContactReason::fromLabel($reason),
            message: $message,
            phone: self::stringOrNull($request->input('form.phone')),
            customerType: self::mapCustomerType($request->input('form.customer_type')),
            orderNumber: self::stringOrNull($request->input('form.order_number')),
            deliveryPostcode: self::stringOrNull($request->input('form.delivery_postcode')),
        );
    }

    private function buildConsent(ContactFormRequest $request): ConsentStatus
    {
        return new ConsentStatus(
            marketing: $request->boolean('consent.marketing'),
            statistics: $request->boolean('consent.statistics'),
            preferences: $request->boolean('consent.preferences'),
            hasResponded: $request->boolean('consent.has_responded'),
        );
    }

    private function buildAttribution(ContactFormRequest $request): MarketingAttribution
    {
        return new MarketingAttribution(
            gclid: self::stringOrNull($request->input('attribution.gclid')),
            utmSource: self::stringOrNull($request->input('attribution.utm_source')),
            utmMedium: self::stringOrNull($request->input('attribution.utm_medium')),
            utmCampaign: self::stringOrNull($request->input('attribution.utm_campaign')),
            utmContent: self::stringOrNull($request->input('attribution.utm_content')),
            utmTerm: self::stringOrNull($request->input('attribution.utm_term')),
        );
    }

    private function buildContext(ContactFormRequest $request): SubmissionContext
    {
        return new SubmissionContext(
            clientTimestamp: self::parseTimestampOrNow($request->input('context.timestamp')),
            ipAddress: $request->ip() ?? '0.0.0.0',
            pageUrl: self::stringOrNull($request->input('context.page_url')),
            referrerUrl: self::stringOrNull($request->input('context.referrer_url')),
            userAgent: self::stringOrNull($request->input('context.user_agent')),
        );
    }

    /**
     * Parse timestamp string or fall back to current time.
     *
     * Graceful handling ensures form submissions don't fail due to
     * malformed client timestamps - the timestamp is metadata, not
     * critical business data.
     */
    private static function parseTimestampOrNow(mixed $timestamp): DateTimeImmutable
    {
        if (!\is_string($timestamp)) {
            return new DateTimeImmutable();
        }

        try {
            return new DateTimeImmutable($timestamp);
        } catch (DateMalformedStringException) {
            return new DateTimeImmutable();
        }
    }

    /**
     * @throws InvalidEnumValueException When ProductSource value is invalid
     */
    private function buildProduct(ContactFormRequest $request): ?SelectedProduct
    {
        if (!$request->has('product') || $request->input('product') === null) {
            return null;
        }

        /** @var string $sku Validated by FormRequest */
        $sku = $request->input('product.sku');

        $source = self::stringOrNull($request->input('product.source'));

        // Use integer() which handles both JSON (int) and form-encoded (string "5") requests
        // Returns 0 for null/missing, so we check filled() first (has() is true even for null)
        $quantity = $request->filled('form.quantity') ? $request->integer('form.quantity') : null;

        return new SelectedProduct(
            sku: $sku,
            title: self::stringOrNull($request->input('product.title')),
            price: self::stringOrNull($request->input('product.price')),
            url: self::stringOrNull($request->input('product.url')),
            source: $source !== null ? ProductSource::fromValue($source) : null,
            manualUrl: self::stringOrNull($request->input('product.manual_url')),
            quantity: $quantity,
        );
    }

    /**
     * Map customer type string to enum, with 'prefer_not_to_say' → null.
     *
     * @throws InvalidEnumValueException When CustomerType value is invalid
     */
    private static function mapCustomerType(mixed $value): ?CustomerType
    {
        if (!\is_string($value) || $value === 'prefer_not_to_say') {
            return null;
        }

        return CustomerType::fromValue($value);
    }

    /**
     * Convert mixed input to string or null.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
