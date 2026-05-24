<?php

declare(strict_types=1);

namespace App\Application\Conversion\Enums;

/**
 * Backing values match the `ad_platform` column on `customer_service.contact_submission_actions`.
 */
enum AdPlatform: string
{
    case Google = 'google';
    case Bing = 'bing';
}
