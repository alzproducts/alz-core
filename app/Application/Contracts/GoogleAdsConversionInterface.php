<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Conversion\GoogleConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;

/**
 * Uploads offline conversions to Google Ads.
 *
 * Separated from {@see GoogleAdsClientInterface} (ISP — reads vs writes,
 * different SDK service: ConversionUploadService vs GoogleAdsService).
 */
interface GoogleAdsConversionInterface
{
    /**
     * Upload a single click conversion attributed to the given gclid and
     * at least one user identifier (email and/or phone).
     *
     * The implementation hashes PII (SHA-256, lowercased + trimmed) internally
     * before sending; callers pass plain values.
     *
     * @throws ExternalServiceUnavailableException When the API is unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials are invalid or expired
     * @throws InvalidApiRequestException When Google rejects the conversion data (e.g. expired gclid, missing action ID)
     */
    public function uploadConversion(ConversionType $type, GoogleConversionUploadDTO $data): void;
}
