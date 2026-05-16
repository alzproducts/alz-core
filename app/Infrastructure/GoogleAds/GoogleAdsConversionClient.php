<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\GoogleAdsConversionClientInterface;
use App\Domain\Conversion\Enums\ConversionType;
use App\Domain\Conversion\ValueObjects\ClickConversionData;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use Google\Ads\GoogleAds\V22\Common\UserIdentifier;
use Google\Ads\GoogleAds\V22\Services\ClickConversion;
use Google\Ads\GoogleAds\V22\Services\UploadClickConversionsRequest;
use Webmozart\Assert\Assert;

/**
 * Uploads offline conversions to Google Ads.
 *
 * Builds {@see ClickConversion} protobufs (with gclid + SHA-256 hashed email) and delegates
 * the request execution + exception translation to {@see GoogleAdsTransport}.
 *
 * @template-pattern API Client Business Logic
 */
final readonly class GoogleAdsConversionClient implements GoogleAdsConversionClientInterface
{
    public function __construct(
        private GoogleAdsTransport $transport,
        private GoogleAdsConfig $config,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     */
    public function uploadConversion(ConversionType $type, ClickConversionData $data): void
    {
        $conversion = $this->buildClickConversion($type, $data);

        $request = new UploadClickConversionsRequest();
        $request->setCustomerId($this->config->customerId);
        $request->setConversions([$conversion]);
        $request->setPartialFailure(true);

        $this->transport->uploadClickConversion($request);
    }

    private function buildClickConversion(ConversionType $type, ClickConversionData $data): ClickConversion
    {
        $actionResourceName = \sprintf(
            'customers/%s/conversionActions/%s',
            $this->config->customerId,
            $this->resolveActionId($type),
        );

        $userIdentifier = new UserIdentifier();
        $userIdentifier->setHashedEmail(self::hashEmail($data->email));

        $conversion = new ClickConversion();
        $conversion->setConversionAction($actionResourceName);
        $conversion->setGclid($data->gclid);
        $conversion->setConversionDateTime($data->convertedAt->format('Y-m-d H:i:sP'));
        $conversion->setUserIdentifiers([$userIdentifier]);

        if ($data->value !== null) {
            $conversion->setConversionValue($data->value->toNet());
            $conversion->setCurrencyCode($data->value->currency);
        }

        return $conversion;
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
}
