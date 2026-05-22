<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\BingAdsConversionInterface;
use App\Application\Conversion\BingConversionUploadDTO;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Phone\PhoneNormalisationService;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\OfflineConversion;
use Webmozart\Assert\Assert;

/**
 * Builds {@see OfflineConversion} models from application DTOs and delegates
 * the upload to {@see BingAdsConversionClient}.
 */
final readonly class BingAdsConversionService implements BingAdsConversionInterface
{
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
     */
    public function uploadOfflineConversion(ConversionType $type, BingConversionUploadDTO $data): void
    {
        $conversion = $this->buildOfflineConversion($type, $data);

        $this->client->uploadOfflineConversion($conversion);
    }

    private function buildOfflineConversion(ConversionType $type, BingConversionUploadDTO $data): OfflineConversion
    {
        $fields = [
            'ConversionName' => $this->resolveGoalName($type),
            'ConversionTime' => $data->convertedAt,
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

    private function resolveGoalName(ConversionType $type): string
    {
        $goalName = match ($type) {
            ConversionType::LeadReceived => $this->config->offlineLeadConversionGoalName,
            ConversionType::QuoteIssued => null,
        };

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
