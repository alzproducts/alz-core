<?php

declare(strict_types=1);

namespace App\Infrastructure\Ingest\ContactSubmission\Mappers;

use App\Domain\ContactSubmission\Enums\ProductSource;
use App\Domain\ContactSubmission\ValueObjects\ConsentStatus;
use App\Domain\ContactSubmission\ValueObjects\ContactFormData;
use App\Domain\ContactSubmission\ValueObjects\ContactSubmission;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\ContactSubmission\ValueObjects\SelectedProduct;
use App\Domain\ContactSubmission\ValueObjects\SubmissionContext;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Ingest\ContactSubmission\Models\ContactSubmissionModel;

/**
 * Maps between ContactSubmissionModel (Eloquent) and ContactSubmission (Domain).
 *
 * Handles transformations including:
 * - Enum casting (handled by Eloquent, but extracted here for clarity)
 * - JSONB ↔ SelectedProduct value object
 * - Flattened attribution ↔ MarketingAttribution value object
 * - Consent columns ↔ ConsentStatus value object
 */
final class ContactSubmissionMapper
{
    /**
     * Convert Domain ContactSubmission to Eloquent model attributes.
     *
     * @return array<string, mixed>
     */
    public static function toModelAttributes(ContactSubmission $submission): array
    {
        return [
            // Core form data
            'name' => $submission->form->name,
            'email' => $submission->form->email,
            'reason' => $submission->form->reason,
            'message' => $submission->form->message,
            'phone' => $submission->form->phone,
            'customer_type' => $submission->form->customerType,
            'order_number' => $submission->form->orderNumber,
            'delivery_postcode' => $submission->form->deliveryPostcode,

            // Product context (JSONB)
            'product' => $submission->product?->toArray(),
            'quantity' => $submission->product?->quantity,

            // User identification
            'shopwired_customer_id' => $submission->shopwiredCustomerId,

            // Consent
            'consent_marketing' => $submission->consent->marketing,
            'consent_statistics' => $submission->consent->statistics,
            'consent_preferences' => $submission->consent->preferences,
            'consent_has_responded' => $submission->consent->hasResponded,

            // Attribution (flattened)
            'gclid' => $submission->attribution->gclid,
            'gclsrc' => $submission->attribution->gclsrc,
            'wbraid' => $submission->attribution->wbraid,
            'gbraid' => $submission->attribution->gbraid,
            'msclkid' => $submission->attribution->msclkid,
            'fbclid' => $submission->attribution->fbclid,
            'utm_source' => $submission->attribution->utmSource,
            'utm_medium' => $submission->attribution->utmMedium,
            'utm_campaign' => $submission->attribution->utmCampaign,
            'utm_content' => $submission->attribution->utmContent,
            'utm_term' => $submission->attribution->utmTerm,

            // Context
            'page_url' => $submission->context->pageUrl,
            'referrer_url' => $submission->context->referrerUrl,
            'user_agent' => $submission->context->userAgent,
            'client_timestamp' => $submission->context->clientTimestamp,
            'ip_address' => $submission->context->ipAddress,
        ];
    }

    /**
     * Convert Eloquent model to Domain ContactSubmission.
     *
     * @throws MalformedStoredDataException If product JSONB data is corrupted
     */
    public static function fromModel(ContactSubmissionModel $model): ContactSubmission
    {
        return new ContactSubmission(
            form: new ContactFormData(
                name: $model->name,
                email: $model->email,
                reason: $model->reason,
                message: $model->message,
                phone: $model->phone,
                customerType: $model->customer_type,
                orderNumber: $model->order_number,
                deliveryPostcode: $model->delivery_postcode,
            ),
            consent: new ConsentStatus(
                marketing: $model->consent_marketing,
                statistics: $model->consent_statistics,
                preferences: $model->consent_preferences,
                hasResponded: $model->consent_has_responded,
            ),
            attribution: new MarketingAttribution(
                gclid: $model->gclid,
                gclsrc: $model->gclsrc,
                wbraid: $model->wbraid,
                gbraid: $model->gbraid,
                msclkid: $model->msclkid,
                fbclid: $model->fbclid,
                utmSource: $model->utm_source,
                utmMedium: $model->utm_medium,
                utmCampaign: $model->utm_campaign,
                utmContent: $model->utm_content,
                utmTerm: $model->utm_term,
            ),
            context: new SubmissionContext(
                clientTimestamp: $model->client_timestamp->toDateTimeImmutable(),
                ipAddress: $model->ip_address,
                pageUrl: $model->page_url,
                referrerUrl: $model->referrer_url,
                userAgent: $model->user_agent,
            ),
            product: self::buildProduct($model),
            shopwiredCustomerId: $model->shopwired_customer_id,
            submittedAt: $model->created_at->toDateTimeImmutable(),
        );
    }

    /**
     * Build SelectedProduct from JSONB column.
     *
     * Validates JSONB structure before constructing value object.
     * Throws domain exception on corruption rather than letting
     * TypeError/ValueError propagate.
     *
     * @throws MalformedStoredDataException If JSONB data is corrupted
     */
    private static function buildProduct(ContactSubmissionModel $model): ?SelectedProduct
    {
        if ($model->product === null) {
            return null;
        }

        $data = $model->product;
        $productId = self::extractRequiredProductId($data);
        $source = self::extractOptionalSource($data);

        return new SelectedProduct(
            productId: $productId,
            sku: self::extractString($data, 'sku'),
            title: self::extractString($data, 'title'),
            price: self::extractString($data, 'price'),
            url: self::extractString($data, 'url'),
            source: $source,
            manualUrl: self::extractString($data, 'manual_url'),
            quantity: isset($data['quantity']) && \is_int($data['quantity']) ? $data['quantity'] : null,
        );
    }

    /**
     * Extract and validate required product_id field.
     *
     * @param array<string, mixed> $data
     *
     * @throws MalformedStoredDataException If product_id is missing or invalid
     */
    private static function extractRequiredProductId(array $data): IntId
    {
        if (!isset($data['product_id']) || !\is_int($data['product_id'])) {
            throw new MalformedStoredDataException(
                'contact_submissions.product',
                'missing or invalid required field: product_id',
            );
        }

        return IntId::fromTrusted($data['product_id']);
    }

    /**
     * Extract and validate optional source enum.
     *
     * @param array<string, mixed> $data
     *
     * @throws MalformedStoredDataException If source value is invalid
     */
    private static function extractOptionalSource(array $data): ?ProductSource
    {
        if (!isset($data['source']) || !\is_string($data['source'])) {
            return null;
        }

        $source = ProductSource::tryFrom($data['source']);

        if ($source === null) {
            throw new MalformedStoredDataException(
                'contact_submissions.product',
                'invalid source value',
            );
        }

        return $source;
    }

    /**
     * Extract optional string field from data array.
     *
     * @param array<string, mixed> $data
     */
    private static function extractString(array $data, string $key): ?string
    {
        return isset($data[$key]) && \is_string($data[$key]) ? $data[$key] : null;
    }
}
