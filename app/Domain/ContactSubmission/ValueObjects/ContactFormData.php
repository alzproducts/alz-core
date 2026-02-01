<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\ContactSubmission\Enums\ContactReason;
use App\Domain\Customer\Enums\CustomerType;
use Webmozart\Assert\Assert;

/**
 * Core form fields from the contact form submission.
 *
 * Note: CustomerType uses null to represent "Prefer not to say" - the form's
 * DTO layer maps this UI option to null rather than using an enum case.
 */
final readonly class ContactFormData
{
    public function __construct(
        public string $name,
        public string $email,
        public ContactReason $reason,
        public string $message,
        public ?string $phone = null,
        public ?CustomerType $customerType = null,
        public ?string $orderNumber = null,
        public ?string $deliveryPostcode = null,
    ) {
        Assert::notEmpty($name, 'Name is required');
        Assert::notEmpty($email, 'Email is required');
        Assert::contains($email, '@', 'Email must contain @');
        Assert::notEmpty($message, 'Message is required');

        if ($phone !== null) {
            Assert::maxLength($phone, 50, 'Phone number too long');
        }

        if ($orderNumber !== null) {
            Assert::maxLength($orderNumber, 20, 'Order number too long');
        }

        if ($deliveryPostcode !== null) {
            Assert::maxLength($deliveryPostcode, 20, 'Delivery postcode too long');
        }
    }
}
