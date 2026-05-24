<?php

declare(strict_types=1);

namespace App\Application\Contracts;

use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

interface BingAdsConversionInterface
{
    /**
     * Upload a single offline conversion attributed to the given msclkid + email.
     *
     * The implementation hashes PII (SHA-256, lowercased + trimmed) internally
     * before sending; callers pass plain values.
     *
     * @throws ExternalServiceUnavailableException When the API is unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials are invalid or expired
     * @throws InvalidApiRequestException When Bing rejects the conversion data (e.g. unknown goal, duplicate conversion)
     * @throws InvalidApiResponseException When the OAuth token response is malformed
     * @throws UnsupportedConversionTypeException When Bing does not support the given ConversionType
     */
    public function uploadOfflineConversion(ConversionType $type, BingConversionUploadDTO $data): void;
}
