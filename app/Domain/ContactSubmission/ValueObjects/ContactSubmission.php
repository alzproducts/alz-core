<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use DateTimeImmutable;

/**
 * Aggregate root for a contact form submission.
 *
 * Combines all value objects into a single immutable snapshot.
 * This represents the complete submission before persistence.
 */
final readonly class ContactSubmission
{
    public function __construct(
        public ContactFormData $form,
        public ConsentStatus $consent,
        public MarketingAttribution $attribution,
        public SubmissionContext $context,
        public ?SelectedProduct $product = null,
        public ?string $shopwiredCustomerId = null,
        public ?DateTimeImmutable $submittedAt = null,
    ) {}

    /**
     * Get the base subject line for HelpScout conversation.
     *
     * Note: Order number is NOT included here. The service layer validates
     * whether the customer email matches the order before enriching the subject.
     * This prevents unvalidated customer-supplied order numbers in subjects.
     */
    public function helpScoutSubject(): string
    {
        $reason = $this->form->reason->label();

        return "[{$reason}] Contact Us Form";
    }
}
