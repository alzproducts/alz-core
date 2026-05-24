<?php

declare(strict_types=1);

namespace App\Presentation\Http\ContactForm\Mappers;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\Gclid;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\Msclkid;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Shared\Money\ValueObjects\Money;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\ContactForm\DTOs\ContactFormRequestDTO;
use App\Presentation\Http\ContactForm\DTOs\FormSectionRequestDTO;
use DateMalformedStringException;
use DateTimeImmutable;

/**
 * Maps typed DTOs to Domain value objects.
 *
 * Handles:
 * - Enum conversion (reason labels → enum, customer type with 'prefer_not_to_say' → null)
 * - IP address injection (not from DTO, passed separately)
 * - Timestamp parsing with graceful fallback
 */
final readonly class ContactSubmissionMapper
{
    /**
     * Transform request DTO to Domain aggregate.
     *
     * @param ContactFormRequestDTO $data Validated request data
     * @param string $ipAddress Client IP (from request, not DTO)
     *
     * @throws InvalidEnumValueException When enum values don't match expected values
     * @throws InvalidFormatException
     */
    public function toDomain(ContactFormRequestDTO $data, string $ipAddress): ContactSubmission
    {
        return new ContactSubmission(
            form: $this->mapFormData($data->form),
            consent: $this->mapConsent($data),
            attribution: $this->mapAttribution($data),
            context: $this->mapContext($data, $ipAddress),
            product: $this->mapProduct($data),
            shopwiredCustomerId: $data->user?->customerId,
        );
    }

    /**
     * @throws InvalidEnumValueException When reason label doesn't match any ContactReason
     */
    private function mapFormData(FormSectionRequestDTO $form): ContactFormData
    {
        return new ContactFormData(
            name: $form->name,
            email: $form->email,
            reason: ContactReason::fromLabel($form->reason),
            message: $form->message,
            phone: $form->phone,
            customerType: self::mapCustomerType($form->customerType),
            orderNumber: $form->orderNumber,
            deliveryPostcode: $form->deliveryPostcode,
        );
    }

    private function mapConsent(ContactFormRequestDTO $data): ConsentStatus
    {
        return new ConsentStatus(
            marketing: $data->consent->marketing,
            statistics: $data->consent->statistics,
            preferences: $data->consent->preferences,
            hasResponded: $data->consent->hasResponded,
        );
    }

    /**
     * @throws InvalidFormatException
     */
    private function mapAttribution(ContactFormRequestDTO $data): MarketingAttribution
    {
        if ($data->attribution === null) {
            return MarketingAttribution::empty();
        }

        return new MarketingAttribution(
            gclid: Gclid::fromNullableForm($data->attribution->gclid),
            gclsrc: $data->attribution->gclsrc,
            wbraid: $data->attribution->wbraid,
            gbraid: $data->attribution->gbraid,
            msclkid: Msclkid::fromNullableForm($data->attribution->msclkid),
            fbclid: $data->attribution->fbclid,
            utmSource: $data->attribution->utmSource,
            utmMedium: $data->attribution->utmMedium,
            utmCampaign: $data->attribution->utmCampaign,
            utmContent: $data->attribution->utmContent,
            utmTerm: $data->attribution->utmTerm,
        );
    }

    private function mapContext(ContactFormRequestDTO $data, string $ipAddress): SubmissionContext
    {
        return new SubmissionContext(
            clientTimestamp: self::parseTimestampOrNow($data->context->timestamp),
            ipAddress: $ipAddress,
            pageUrl: $data->context->pageUrl,
            referrerUrl: $data->context->referrerUrl,
            userAgent: $data->context->userAgent,
        );
    }

    /**
     * @throws InvalidEnumValueException When ProductSource value is invalid
     */
    private function mapProduct(ContactFormRequestDTO $data): ?SelectedProduct
    {
        if ($data->product === null) {
            return null;
        }

        return new SelectedProduct(
            productId: IntId::from($data->product->productId),
            sku: $data->product->sku,
            title: $data->product->title,
            price: $data->product->price !== null
                ? Money::exclusiveFromString($data->product->price)
                : null,
            url: $data->product->url,
            source: $data->product->source !== null
                ? ProductSource::fromValue($data->product->source)
                : null,
            manualUrl: $data->product->manualUrl,
            quantity: $data->form->quantity,
        );
    }

    /**
     * Map customer type string to enum, with 'prefer_not_to_say' → null.
     *
     * @throws InvalidEnumValueException When CustomerType value is invalid
     */
    private static function mapCustomerType(?string $value): ?CustomerType
    {
        if ($value === null || $value === 'prefer_not_to_say') {
            return null;
        }

        return CustomerType::fromValue($value);
    }

    /**
     * Parse timestamp string or fall back to current time.
     *
     * Graceful handling ensures form submissions don't fail due to
     * malformed client timestamps - the timestamp is metadata, not
     * critical business data.
     */
    private static function parseTimestampOrNow(string $timestamp): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($timestamp);
        } catch (DateMalformedStringException) {
            return new DateTimeImmutable();
        }
    }
}
