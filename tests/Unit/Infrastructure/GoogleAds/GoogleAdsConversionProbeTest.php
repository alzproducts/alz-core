<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\GoogleAds;

use App\Infrastructure\GoogleAds\GoogleAdsConfig;
use App\Infrastructure\GoogleAds\GoogleAdsConversionProbe;
use App\Infrastructure\GoogleAds\GoogleAdsTransport;
use Google\Ads\GoogleAds\V25\Services\ClickConversion;
use Google\Ads\GoogleAds\V25\Services\UploadClickConversionsRequest;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Captures the request the probe hands to the transport; the transport seam is
 * mocked, the protobufs are real (per tests/CLAUDE.md).
 */
#[CoversClass(GoogleAdsConversionProbe::class)]
final class GoogleAdsConversionProbeTest extends TestCase
{
    private const string CUSTOMER_ID = '1234567890';
    private const string LEAD_ACTION_ID = '9000000001';
    private const string QUOTE_ACTION_ID = '9000000002';

    private GoogleAdsTransport&MockInterface $mockTransport;
    private GoogleAdsConversionProbe $probe;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $config = new GoogleAdsConfig(
            clientId: 'oauth-client-id',
            clientSecret: 'oauth-client-secret',
            refreshToken: 'oauth-refresh-token',
            developerToken: 'developer-token',
            customerId: self::CUSTOMER_ID,
            leadConversionActionId: self::LEAD_ACTION_ID,
            quoteConversionActionId: self::QUOTE_ACTION_ID,
        );

        $this->mockTransport = Mockery::mock(GoogleAdsTransport::class);
        $this->probe = new GoogleAdsConversionProbe($this->mockTransport, $config);
    }

    #[Test]
    public function it_sends_a_validate_only_request(): void
    {
        $capture = $this->captureUploadRequest();

        $this->probe->probeUpload();

        $this->assertNotNull($capture->request, 'Transport was not invoked');
        $this->assertTrue($capture->request->getValidateOnly());
    }

    #[Test]
    public function it_sends_a_partial_failure_request_for_the_configured_customer(): void
    {
        $capture = $this->captureUploadRequest();

        $this->probe->probeUpload();

        $this->assertNotNull($capture->request, 'Transport was not invoked');
        $this->assertTrue($capture->request->getPartialFailure());
        $this->assertSame(self::CUSTOMER_ID, $capture->request->getCustomerId());
    }

    #[Test]
    public function it_sends_the_fabricated_gclid_against_the_lead_conversion_action(): void
    {
        $capture = $this->captureUploadRequest();

        $this->probe->probeUpload();

        $conversion = $this->firstConversion($capture);
        $this->assertSame('probe-invalid-gclid-cor-224', $conversion->getGclid());
        $this->assertSame(
            'customers/' . self::CUSTOMER_ID . '/conversionActions/' . self::LEAD_ACTION_ID,
            $conversion->getConversionAction(),
        );
    }

    #[Test]
    public function it_sends_the_hashed_reserved_probe_email_as_the_only_user_identifier(): void
    {
        $capture = $this->captureUploadRequest();

        $this->probe->probeUpload();

        $identifiers = \iterator_to_array($this->firstConversion($capture)->getUserIdentifiers());

        $this->assertCount(1, $identifiers);
        $this->assertSame(
            \hash('sha256', 'conversion-probe@invalid.example'),
            $identifiers[0]->getHashedEmail(),
        );
    }

    #[Test]
    public function it_stamps_the_conversion_date_time_in_the_google_ads_format(): void
    {
        $capture = $this->captureUploadRequest();

        $this->probe->probeUpload();

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/',
            $this->firstConversion($capture)->getConversionDateTime(),
        );
    }

    private function captureUploadRequest(): object
    {
        $capture = new class () {
            public ?UploadClickConversionsRequest $request = null;
        };

        $this->mockTransport
            ->shouldReceive('uploadClickConversion')
            ->once()
            ->withArgs(static function (UploadClickConversionsRequest $request) use ($capture): bool {
                $capture->request = $request;

                return true;
            });

        return $capture;
    }

    private function firstConversion(object $capture): ClickConversion
    {
        $this->assertNotNull($capture->request, 'Transport was not invoked');

        $conversions = \iterator_to_array($capture->request->getConversions());
        $this->assertCount(1, $conversions);

        return $conversions[0];
    }
}
