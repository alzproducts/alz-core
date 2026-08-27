<?php

declare(strict_types=1);

namespace App\Infrastructure\GoogleAds;

use App\Application\Contracts\Conversion\GoogleAdsConversionProbeInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use DateTimeImmutable;
use Google\Ads\GoogleAds\V25\Common\UserIdentifier;
use Google\Ads\GoogleAds\V25\Services\ClickConversion;
use Google\Ads\GoogleAds\V25\Services\UploadClickConversionsRequest;
use Override;
use Webmozart\Assert\Assert;

/**
 * Builds and sends the invalid-gclid probe upload.
 *
 * Deliberately mirrors {@see GoogleAdsConversionService::buildClickConversion()}
 * rather than reusing it: the production upload path must stay behaviourally
 * untouched, and this request additionally sets `validate_only`.
 */
final readonly class GoogleAdsConversionProbe implements GoogleAdsConversionProbeInterface
{
    /**
     * Reserved `.example` address — RFC 2606 guarantees it can never route to a real person.
     */
    private const string PROBE_EMAIL = 'conversion-probe@invalid.example';

    public function __construct(
        private GoogleAdsTransport $transport,
        private GoogleAdsConfig $config,
    ) {}

    /**
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function probeUpload(): void
    {
        $request = new UploadClickConversionsRequest();
        $request->setCustomerId($this->config->customerId);
        $request->setConversions([$this->buildProbeConversion()]);
        $request->setPartialFailure(true);
        // Google validates and executes nothing, so the probe cannot land data in the Ads account.
        $request->setValidateOnly(true);

        $this->transport->uploadClickConversion($request);
    }

    private function buildProbeConversion(): ClickConversion
    {
        $conversion = new ClickConversion();
        $conversion->setConversionAction($this->leadConversionActionResourceName());
        $conversion->setGclid(self::PROBE_GCLID);
        // Built at send time, never in the constructor — Octane keeps this object alive across requests.
        $conversion->setConversionDateTime(new DateTimeImmutable()->format('Y-m-d H:i:sP'));
        $conversion->setUserIdentifiers([self::buildProbeIdentifier()]);

        return $conversion;
    }

    private function leadConversionActionResourceName(): string
    {
        $actionId = $this->config->leadConversionActionId;

        Assert::notNull(
            $actionId,
            'Google Ads lead conversion action ID must be configured before probing the conversion upload path',
        );

        return \sprintf('customers/%s/conversionActions/%s', $this->config->customerId, $actionId);
    }

    private static function buildProbeIdentifier(): UserIdentifier
    {
        $identifier = new UserIdentifier();
        $identifier->setHashedEmail(\hash('sha256', self::PROBE_EMAIL));

        return $identifier;
    }
}
