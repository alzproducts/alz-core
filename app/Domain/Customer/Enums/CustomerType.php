<?php

declare(strict_types=1);

namespace App\Domain\Customer\Enums;

/**
 * Type of customer for business classification.
 *
 * Used across the codebase for customer segmentation, routing decisions,
 * and analytics. B2B customers (NHS, care homes, etc.) may receive
 * different pricing, priority, or communication.
 */
enum CustomerType: string
{
    case Personal = 'personal';
    case Nhs = 'nhs';
    case Government = 'government';
    case CareHome = 'care_home';
    case Charity = 'charity';
    case OtherBusiness = 'other_business';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Personal => 'Personal',
            self::Nhs => 'NHS',
            self::Government => 'Government',
            self::CareHome => 'Care Home',
            self::Charity => 'Charity',
            self::OtherBusiness => 'Other Business',
        };
    }

    /**
     * Determines if this is a B2B customer type.
     */
    public function isBusinessCustomer(): bool
    {
        return match ($this) {
            self::Nhs,
            self::Government,
            self::CareHome,
            self::Charity,
            self::OtherBusiness => true,
            self::Personal => false,
        };
    }
}
