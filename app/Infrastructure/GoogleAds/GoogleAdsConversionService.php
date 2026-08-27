<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Conversion\ConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Infrastructure\Phone\PhoneNormalisationService;
use Google\Ads\GoogleAds\V25\Common\UserIdentifier;
use Google\Ads\GoogleAds\V25\Services\ClickConversion;
use Webmozart\Assert\Assert;

/**
 * Builds {@see ClickConversion} protobufs from application DTOs and delegates
 * the upload to {@see GoogleAdsConversionClient}.
 */
final readonly class GoogleAdsConversionService
{
    public function __construct(
        private GoogleAdsConversionClient $client,
        private GoogleAdsConfig $config,
        private PhoneNormalisationService $phoneNormalisationService,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     */
    public function uploadConversion(ConversionType $type, ConversionUploadDTO $data): void
    {
        $conversion = $this->buildClickConversion($type, $data);

        $this->client->uploadConversion($conversion);
    }

    private function buildClickConversion(ConversionType $type, ConversionUploadDTO $data): ClickConversion
    {
        $actionResourceName = \sprintf(
            'customers/%s/conversionActions/%s',
            $this->config->customerId,
            $this->resolveActionId($type),
        );

        $conversion = new ClickConversion();
        $conversion->setConversionAction($actionResourceName);
        $conversion->setGclid($data->clickId);
        $conversion->setConversionDateTime($data->convertedAt->format('Y-m-d H:i:sP'));
        $conversion->setUserIdentifiers($this->buildUserIdentifiers($data));

        if ($data->value !== null) {
            $conversion->setConversionValue($data->value->toNet());
            $conversion->setCurrencyCode($data->value->currency);
        }

        return $conversion;
    }

    /**
     * @return list<UserIdentifier>
     */
    private function buildUserIdentifiers(ConversionUploadDTO $data): array
    {
        $userIdentifiers = [
            ...$this->buildEmailIdentifier($data),
            ...$this->buildPhoneIdentifier($data),
        ];

        Assert::notEmpty($userIdentifiers, 'At least one user identifier (email or valid phone) must resolve');

        return $userIdentifiers;
    }

    /**
     * @return list<UserIdentifier>
     */
    private function buildEmailIdentifier(ConversionUploadDTO $data): array
    {
        if ($data->email === null) {
            return [];
        }

        $identifier = new UserIdentifier();
        $identifier->setHashedEmail(self::hashEmail($data->email));

        return [$identifier];
    }

    /**
     * @return list<UserIdentifier>
     */
    private function buildPhoneIdentifier(ConversionUploadDTO $data): array
    {
        if ($data->phone === null) {
            return [];
        }

        $e164 = $this->phoneNormalisationService->toE164($data->phone);

        if ($e164 === null) {
            return [];
        }

        $identifier = new UserIdentifier();
        $identifier->setHashedPhoneNumber(self::hashPhone($e164));

        return [$identifier];
    }

    private function resolveActionId(ConversionType $type): string
    {
        $actionId = match ($type) {
            ConversionType::LeadReceived => $this->config->leadConversionActionId,
            ConversionType::QuoteIssued => $this->config->quoteConversionActionId,
        };

        Assert::notNull(
            $actionId,
            'Google Ads conversion action ID must be configured before using the conversion client',
        );

        return $actionId;
    }

    private static function hashEmail(string $email): string
    {
        return \hash('sha256', \mb_strtolower(\mb_trim($email)));
    }

    private static function hashPhone(string $e164): string
    {
        return \hash('sha256', $e164);
    }
}
