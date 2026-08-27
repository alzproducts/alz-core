<?php

declare(strict_types=1);

namespace App\Application\Contracts\Conversion;

use App\Application\Conversion\ConversionUploadDTO;
use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\ValueObjects\MarketingAttribution;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Data\InvalidFormatException;

/**
 * One ad platform's (Google, Bing, …) view of the offline-conversion upload
 * pipeline: which conversion types it can receive, how it reads its click ID
 * out of the shared attribution, and how it uploads a resolved conversion.
 */
interface AdPlatformConversionAdapterInterface
{
    public function platform(): AdPlatform;

    public function supports(ConversionType $type): bool;

    /**
     * The platform's click ID as stored (raw, null-check only — no format
     * validation). Called at submit time where today only presence is checked;
     * validating here would move a malformed-click-ID failure into the submit
     * request instead of the upload job.
     */
    public function extractClickId(MarketingAttribution $attribution): ?string;

    /**
     * @throws ExternalServiceUnavailableException When the API is unavailable or rate limited
     * @throws AuthenticationExpiredException When credentials are invalid or expired
     * @throws InvalidApiRequestException When the platform rejects the conversion data
     * @throws InvalidApiResponseException When the API response is malformed
     * @throws UnsupportedConversionTypeException When the platform does not support the given ConversionType
     * @throws InvalidFormatException When the stored click ID fails the platform VO format check
     */
    public function upload(ConversionType $type, ConversionUploadDTO $data): void;
}
