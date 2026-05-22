<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsConversionInterface;
use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\Exceptions\UnsupportedConversionTypeException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Phone\PhoneNormalisationService;
use DateTimeInterface;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\OfflineConversion;
use Webmozart\Assert\Assert;

/**
 * Builds {@see OfflineConversion} models from application DTOs and delegates
 * the upload to {@see BingAdsConversionClient}.
 */
final readonly class BingAdsConversionService implements BingAdsConversionInterface
{
    private const string PLATFORM_NAME = 'Bing Ads';

    public function __construct(
        private BingAdsConversionClient $client,
        private BingAdsConfig $config,
        private PhoneNormalisationService $phoneNormalisationService,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws UnsupportedConversionTypeException When Bing does not support the given ConversionType
     */
    public function uploadOfflineConversion(ConversionType $type, BingConversionUploadDTO $data): void
    {
        $conversion = $this->buildOfflineConversion($type, $data);

        $this->client->uploadOfflineConversion($conversion);
    }

    /**
     * @throws UnsupportedConversionTypeException
     */
    private function buildOfflineConversion(ConversionType $type, BingConversionUploadDTO $data): OfflineConversion
    {
        $fields = [
            'ConversionName' => $this->resolveGoalName($type),
            // SDK serializer's `instanceof \DateTime` check excludes DateTimeImmutable, so we
            // pre-format to the ATOM string the SDK would have emitted (passes through scalar branch).
            'ConversionTime' => $data->convertedAt->format(DateTimeInterface::ATOM),
            'MicrosoftClickId' => $data->msclkid,
            'HashedEmailAddress' => self::hashEmail($data->email),
            ...$this->buildEnhancedMatchFields($data),
            ...$this->buildConversionValueFields($data),
        ];

        return new OfflineConversion($fields);
    }

    /**
     * @return array<string, string>
     */
    private function buildEnhancedMatchFields(BingConversionUploadDTO $data): array
    {
        if ($data->phone === null) {
            return [];
        }

        $e164 = $this->phoneNormalisationService->toE164($data->phone);

        if ($e164 === null) {
            return [];
        }

        return ['HashedPhoneNumber' => self::hashPhone($e164)];
    }

    /**
     * @return array<string, string|float>
     */
    private function buildConversionValueFields(BingConversionUploadDTO $data): array
    {
        if ($data->value === null) {
            return [];
        }

        return [
            'ConversionValue' => $data->value->toNet(),
            'ConversionCurrencyCode' => $data->value->currency,
        ];
    }

    /**
     * @throws UnsupportedConversionTypeException When Bing does not support the given ConversionType
     */
    private function resolveGoalName(ConversionType $type): string
    {
        $goalName = match ($type) {
            ConversionType::LeadReceived => $this->config->offlineLeadConversionGoalName,
            ConversionType::QuoteIssued => throw new UnsupportedConversionTypeException($type, self::PLATFORM_NAME),
        };

        // LeadReceived goal name presence is enforced at config-load time by BingAdsClientFactory::createConversionConfig().
        Assert::notNull(
            $goalName,
            'Bing Ads conversion goal name must be configured before using the conversion client',
        );

        return $goalName;
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
