<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\DTOs;

use App\Application\ContactSubmission\Enums\PotentialConversionSource;
use App\Domain\ContactSubmission\Enums\ActionStatus;
use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * @phpstan-type ProductDetails array<string, mixed>
 */
final readonly class ContactSubmissionListItemDTO
{
    /**
     * @param ProductDetails|null $product
     */
    public function __construct(
        public Guid $id,
        public PotentialConversionSource $source,
        public ?string $name,
        public ?string $email,
        public ?ContactReason $reason,
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
        public ?string $callerPhoneNumber,
    ) {}
}
