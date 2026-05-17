<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Flattened read-projection of a contact submission for the staff dashboard.
 *
 * Aggregates data from three tables — `public_ingest.contact_submissions`,
 * `customer_service.contact_submission_actions`, and `marketing.contact_submission_annotations`
 * — into a single immutable shape suitable for list rendering. Holds native types only;
 * the Presentation layer owns wire formatting.
 *
 * @phpstan-type ProductDetails array<string, mixed>
 */
final readonly class ContactSubmissionListItem
{
    /**
     * @param ProductDetails|null $product Snapshot of the product context (JSONB).
     */
    public function __construct(
        public Guid $id,
        public string $name,
        public string $email,
        public ContactReason $reason,
        public ?CustomerType $customerType,
        public ?string $orderNumber,
        public ?int $quantity,
        public ?array $product,
        public ?string $shopwiredCustomerId,
        public ?string $gclid,
        public ?string $msclkid,
        public ?string $fbclid,
        public ?string $utmSource,
        public ?string $utmMedium,
        public ?string $utmCampaign,
        public ?string $pageUrl,
        public DateTimeImmutable $createdAt,
        public ?string $helpscoutExternalId,
        public ?ActionStatus $leadStatus,
        public ?ActionStatus $quoteStatus,
        public ?bool $isPotentialQuote,
        public ?string $notes,
        public ?DateTimeImmutable $quotedAt,
    ) {}
}
