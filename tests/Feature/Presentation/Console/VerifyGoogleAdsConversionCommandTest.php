<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console;

use App\Application\Contracts\Conversion\GoogleAdsConversionProbeInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Presentation\Console\Commands\VerifyGoogleAdsConversionCommand;
use Illuminate\Console\Command;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Verdict-rule tests for verify:googleads-conversions. Every detail-carrying
 * exception is constructed with an explicit detail argument — the default leaves
 * ->detail equal to the static Sentry-grouping message, which would make the
 * code and ALLOWLISTED assertions vacuous.
 */
#[CoversClass(VerifyGoogleAdsConversionCommand::class)]
final class VerifyGoogleAdsConversionCommandTest extends TestCase
{
    private GoogleAdsConversionProbeInterface&MockInterface $probe;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = Mockery::mock(GoogleAdsConversionProbeInterface::class);
        $this->app->instance(GoogleAdsConversionProbeInterface::class, $this->probe);
    }

    #[Test]
    public function it_passes_when_google_rejects_the_fabricated_gclid(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new InvalidApiRequestException(
                'Google Ads',
                'Errors in mutate operation. [UNPARSEABLE_GCLID]',
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('PASS:')
            ->expectsOutputToContain('Errors in mutate operation. [UNPARSEABLE_GCLID]')
            ->assertExitCode(Command::SUCCESS);
    }

    #[Test]
    public function it_fails_when_google_rejects_the_payload_for_another_reason(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new InvalidApiRequestException(
                'Google Ads',
                'Errors in mutate operation. [NO_CONVERSION_ACTION_FOUND]',
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('FAIL:')
            ->expectsOutputToContain('NO_CONVERSION_ACTION_FOUND')
            ->expectsOutputToContain('the upload path has a real defect')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_reports_inconclusive_when_a_partial_failure_carries_an_allowlist_block(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new InvalidApiRequestException(
                'Google Ads',
                'Errors in mutate operation. [CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE, UNPARSEABLE_GCLID]',
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('INCONCLUSIVE:')
            ->doesntExpectOutputToContain('PASS:')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_reports_inconclusive_when_the_account_is_not_allowlisted(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new AuthenticationExpiredException(
                'Google Ads',
                'CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE - Customer is not allowlisted.',
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('INCONCLUSIVE:')
            ->expectsOutputToContain('CUSTOMER_NOT_ALLOWLISTED_FOR_THIS_FEATURE')
            ->expectsOutputToContain('escalate before merging')
            ->doesntExpectOutputToContain('PASS:')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_with_a_credentials_hint_on_a_plain_authentication_failure(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new AuthenticationExpiredException(
                'Google Ads',
                'AUTHENTICATION_ERROR - OAuth token is invalid.',
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('FAIL:')
            ->expectsOutputToContain('AUTHENTICATION_ERROR - OAuth token is invalid.')
            ->expectsOutputToContain('Check: Google Ads OAuth credentials and refresh token')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_reports_inconclusive_and_surfaces_the_wrapped_message_on_a_transport_failure(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new ExternalServiceUnavailableException(
                'Google Ads',
                previous: new RuntimeException('Request contains an invalid argument.'),
            ));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('INCONCLUSIVE:')
            ->expectsOutputToContain('Request contains an invalid argument.')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_when_google_unexpectedly_accepts_the_probe(): void
    {
        $this->probe->shouldReceive('probeUpload')->once();

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('unexpectedly accepted the probe')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_when_google_ads_configuration_is_missing(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new InvalidConfigurationException('GOOGLE_ADS_LEAD_CONVERSION_ID'));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('FAIL: Google Ads configuration is missing or invalid')
            ->expectsOutputToContain('GOOGLE_ADS_LEAD_CONVERSION_ID')
            ->assertExitCode(Command::FAILURE);
    }

    #[Test]
    public function it_fails_and_surfaces_the_message_on_an_unclassified_error(): void
    {
        $this->probe
            ->shouldReceive('probeUpload')
            ->once()
            ->andThrow(new RuntimeException('boom'));

        $this->artisan('verify:googleads-conversions')
            ->expectsOutputToContain('FAIL: boom')
            ->assertExitCode(Command::FAILURE);
    }

    /**
     * Binding a throwing factory proves the dry run never resolves the probe —
     * stronger than shouldNotReceive(), which would still pass if the container
     * built the whole SDK client chain.
     */
    #[Test]
    public function it_never_resolves_the_probe_during_a_dry_run(): void
    {
        $this->app->bind(
            GoogleAdsConversionProbeInterface::class,
            static function (): GoogleAdsConversionProbeInterface {
                throw new RuntimeException('The probe must not be resolved during a dry run');
            },
        );

        $this->artisan('verify:googleads-conversions', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->expectsOutputToContain(GoogleAdsConversionProbeInterface::PROBE_GCLID)
            ->assertExitCode(Command::SUCCESS);
    }
}
