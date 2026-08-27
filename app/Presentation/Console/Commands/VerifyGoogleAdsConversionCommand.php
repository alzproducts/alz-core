<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Conversion\GoogleAdsConversionProbeInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Console\Command;
use Throwable;

/**
 * Prove the Google Ads V25 conversion upload path works by sending a deliberately
 * invalid conversion and grading Google's structured rejection.
 *
 * ⚠️ PRODUCTION ONLY: This command talks to the LIVE Google Ads API using production
 * credentials. The canonical post-deploy usage is:
 *
 *   railway ssh -s alz-core-worker php artisan verify:googleads-conversions
 *
 * Sanctioned exception — running this locally before merge is explicitly allowed.
 * The request is `validate_only` and carries a fabricated gclid, so Google validates
 * and executes nothing in the Ads account, and the command writes nothing to any
 * database. The audit-trail rationale behind the production-only rule (local runs
 * recording history in the wrong database) therefore does not apply.
 *
 * Verdicts:
 * - PASS      — Google rejected the fabricated gclid with an expected code
 * - FAIL      — any other rejection, credential failure, or clean acceptance
 * - INCONCLUSIVE (exits FAILURE) — allowlist policy block, or no graded response at all
 */
final class VerifyGoogleAdsConversionCommand extends Command
{
    /**
     * Google's gclid-decoding failure codes — the only rejections that prove the
     * round-trip reached payload validation.
     */
    private const array EXPECTED_REJECTION_CODES = ['UNPARSEABLE_GCLID', 'CLICK_NOT_FOUND'];

    private const string ALLOWLIST_MARKER = 'ALLOWLISTED';

    protected $signature = 'verify:googleads-conversions
        {--dry-run : Build and display the probe payload without calling Google}';

    protected $description = 'Probe the Google Ads conversion upload path with a deliberately invalid validate-only conversion';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            return $this->displayDryRun();
        }

        $this->info('Probing Google Ads conversion upload (validate_only)...');

        return $this->runProbe();
    }

    private function runProbe(): int
    {
        try {
            \app(GoogleAdsConversionProbeInterface::class)->probeUpload();
        } catch (InvalidApiRequestException $e) {
            return $this->judgeRejection($e->detail);
        } catch (AuthenticationExpiredException $e) {
            return $this->judgeAuthenticationFailure($e->detail);
        } catch (ExternalServiceUnavailableException $e) {
            return $this->reportTransportFailure($e);
        } catch (InvalidConfigurationException $e) {
            return $this->reportConfigurationFailure($e);
        } catch (Throwable $e) { // @ignoreException - probe command: report the failure to the operator
            return $this->reportUnexpectedError($e);
        }

        return $this->reportUnexpectedAcceptance();
    }

    /**
     * The allowlist branch runs first so a policy block can never be read as a pass.
     */
    private function judgeRejection(string $detail): int
    {
        if (\str_contains($detail, self::ALLOWLIST_MARKER)) {
            return $this->reportAllowlistBlock($detail);
        }

        if (self::containsExpectedRejection($detail)) {
            $this->info('PASS: Google structurally rejected the fabricated gclid — the V25 upload round-trip works.');
            $this->line('  Detail: ' . $detail);

            return self::SUCCESS;
        }

        $this->error('FAIL: Google was reached, but rejected the conversion payload for another reason.');
        $this->line('  Detail: ' . $detail);
        $this->line('  Check: the upload path has a real defect (stale GOOGLE_ADS_LEAD_CONVERSION_ID or invalid user identifier) — fix before merge');

        return self::FAILURE;
    }

    private function judgeAuthenticationFailure(string $detail): int
    {
        if (\str_contains($detail, self::ALLOWLIST_MARKER)) {
            return $this->reportAllowlistBlock($detail);
        }

        $this->error('FAIL: Google rejected our credentials.');
        $this->line('  Detail: ' . $detail);
        $this->line('  Check: Google Ads OAuth credentials and refresh token');

        return self::FAILURE;
    }

    private function reportAllowlistBlock(string $detail): int
    {
        $this->error('INCONCLUSIVE: Google blocked the upload on offline-conversion allowlisting, so the payload was never validated.');
        $this->line('  Detail: ' . $detail);
        $this->line('  Check: escalate before merging — an account-access policy block is not evidence about V25');

        return self::FAILURE;
    }

    private function reportTransportFailure(ExternalServiceUnavailableException $e): int
    {
        $this->error('INCONCLUSIVE: the probe returned no graded rejection.');
        $this->line('  Detail: ' . ($e->getPrevious()?->getMessage() ?? 'no underlying message available'));
        $this->line('  Check: a Google-side validation message above means the V25 round-trip worked — inspect manually; a timeout or unavailable message is a genuine connectivity failure');

        return self::FAILURE;
    }

    private function reportConfigurationFailure(InvalidConfigurationException $e): int
    {
        $this->error('FAIL: Google Ads configuration is missing or invalid — the probe never left the process.');
        $this->line('  Missing or invalid: ' . $e->configKey);
        $this->line('  Check: the five base GOOGLE_ADS_* vars plus GOOGLE_ADS_LEAD_CONVERSION_ID and GOOGLE_ADS_QUOTE_CONVERSION_ID in .env');

        return self::FAILURE;
    }

    private function reportUnexpectedAcceptance(): int
    {
        $this->error('FAIL: Google validation unexpectedly accepted the probe — inspect.');
        $this->line('  Nothing was executed (validate_only), but the probe proved nothing about the V25 upload path.');

        return self::FAILURE;
    }

    private function reportUnexpectedError(Throwable $e): int
    {
        $this->error('FAIL: ' . self::formatError($e));

        return self::FAILURE;
    }

    /**
     * Resolving the probe would build the whole Google Ads SDK client chain and fail
     * fast on missing config, so the dry run reads the gclid off the contract instead.
     */
    private function displayDryRun(): int
    {
        $this->info('Dry run — no Google Ads API call will be made.');
        $this->line('  Target: the Google Ads lead conversion action (GOOGLE_ADS_LEAD_CONVERSION_ID) on the configured customer');
        $this->line('  Request: UploadClickConversions with partial_failure and validate_only both set');
        $this->line('  Gclid: ' . GoogleAdsConversionProbeInterface::PROBE_GCLID . ' (fabricated — Google must reject it)');
        $this->line('  User identifier: the SHA-256 hash of a reserved .example email address');
        $this->line('  Effect: Google validates only — nothing is executed in the Ads account and nothing is written to our database');

        return self::SUCCESS;
    }

    private static function containsExpectedRejection(string $detail): bool
    {
        return \array_any(
            self::EXPECTED_REJECTION_CODES,
            static fn(string $code): bool => \str_contains($detail, $code),
        );
    }

    /**
     * Format an exception message with structured context for operator debugging.
     */
    private static function formatError(Throwable $e): string
    {
        $message = $e->getMessage();
        if (\method_exists($e, 'context')) {
            $ctx = $e->context();
            if ($ctx !== []) {
                $message .= ' — ' . \json_encode($ctx);
            }
        }

        return $message;
    }
}
