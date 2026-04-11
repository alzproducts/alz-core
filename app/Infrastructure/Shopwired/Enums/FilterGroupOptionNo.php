<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Maps known ShopWired filter groups to their optionNo values.
 *
 * These values are hardcoded in the ShopWired system and protected by
 * integration guard tests (CustomerRatingFilterGroupGuardTest,
 * VatReliefFilterGroupGuardTest, OffersFilterGroupGuardTest,
 * ShippingOffersFilterGroupGuardTest, ShippingOptionsFilterGroupGuardTest).
 */
enum FilterGroupOptionNo: int
{
    case VatRelief = 2;
    case Offers = 14;
    case CustomerRating = 15;
    case ShippingOffers = 20;
    case ShippingOptions = 25;
}
