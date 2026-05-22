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
use DateTimeZone;
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
            // Bing's REST endpoint requires UTC xs:dateTime; non-UTC input is the documented
            // #1 cause of error 5614 (FutureConversionTimeCannotBeSet). The Z-suffix format
            // sidesteps the SDK serializer's `instanceof \DateTime` check (which excludes
            // DateTimeImmutable) by passing through the scalar branch.
            'ConversionTime' => $data->convertedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
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
        return \hash('sha256', self::normaliseEmail($email));
    }

    /**
     * Per Microsoft's Enhanced Conversions spec: trim, lowercase, drop the `+alias`
     * sub-address, and additionally strip dots from Gmail/Googlemail local parts.
     */
    private static function normaliseEmail(string $email): string
    {
        $normalised = \mb_strtolower(\mb_trim($email));

        $atPos = \mb_strrpos($normalised, '@');
        if ($atPos === false) {
            return $normalised;
        }

        $local = \mb_substr($normalised, 0, $atPos);
        $domain = \mb_substr($normalised, $atPos + 1);

        return self::normaliseEmailLocalPart($local, $domain) . '@' . $domain;
    }

    private static function normaliseEmailLocalPart(string $local, string $domain): string
    {
        $plusPos = \mb_strpos($local, '+');
        if ($plusPos !== false) {
            $local = \mb_substr($local, 0, $plusPos);
        }

        if (\in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
            $local = \str_replace('.', '', $local);
        }

        return $local;
    }

    private static function hashPhone(string $e164): string
    {
        return \hash('sha256', $e164);
    }
}
