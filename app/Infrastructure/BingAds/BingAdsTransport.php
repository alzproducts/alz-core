<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\ValueObjects\DateRange;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Microsoft\BingAds\Auth\ApiEnvironment;
use Microsoft\BingAds\Auth\AuthorizationData;
use Microsoft\BingAds\Auth\OAuthDesktopMobileAuthCodeGrant;
use Microsoft\BingAds\Auth\OAuthTokens;
use Microsoft\BingAds\Auth\ServiceClient;
use Microsoft\BingAds\Auth\ServiceClientType;
use Microsoft\BingAds\V13\CustomerManagement\GetAccountRequest;
use Microsoft\BingAds\V13\Reporting\AccountThroughCampaignReportScope;
use Microsoft\BingAds\V13\Reporting\CampaignPerformanceReportColumn;
use Microsoft\BingAds\V13\Reporting\CampaignPerformanceReportRequest;
use Microsoft\BingAds\V13\Reporting\Date;
use Microsoft\BingAds\V13\Reporting\PollGenerateReportRequest;
use Microsoft\BingAds\V13\Reporting\ReportAggregation;
use Microsoft\BingAds\V13\Reporting\ReportFormat;
use Microsoft\BingAds\V13\Reporting\ReportRequestStatusType;
use Microsoft\BingAds\V13\Reporting\ReportTime;
use Microsoft\BingAds\V13\Reporting\SubmitGenerateReportRequest;
use RuntimeException;
use SoapClient;
use SoapFault;
use SoapVar;
use ZipArchive;

/**
 * Transport layer for Bing Ads SDK.
 *
 * Wraps SOAP calls to Microsoft Advertising API and handles exception translation.
 * This separation allows the client to focus solely on business logic.
 *
 * Key responsibilities:
 * - Build SDK ServiceClient with OAuth tokens from SessionManager
 * - Execute SOAP operations via the SDK
 * - Translate SOAP faults to domain exceptions
 * - Log failures with context before translation
 *
 * Unlike Google Ads SDK which handles token refresh internally,
 * Bing Ads requires manual OAuth management via BingAdsSessionManager.
 *
 * @template-pattern API Client SDK Transport
 */
final class BingAdsTransport
{
    private const string SERVICE_NAME = 'Bing Ads';

    /**
     * SOAP fault codes indicating authentication failure.
     * https://learn.microsoft.com/en-us/advertising/customer-management-service/operation-error-codes
     */
    private const array AUTH_ERROR_CODES = [
        105, // InvalidCredentials
        106, // UserIsNotAuthorized
        109, // InvalidOAuthAccessToken
        123, // InsufficientPermissions
    ];

    /**
     * SOAP fault codes indicating rate limiting.
     */
    private const array RATE_LIMIT_CODES = [
        117, // CallRateExceeded
        207, // QuotaExceeded
    ];

    /**
     * Columns to include in campaign performance reports.
     * These map to CampaignMetrics domain value object fields.
     *
     * @var list<string>
     */
    private const array REPORT_COLUMNS = [
        CampaignPerformanceReportColumn::CampaignId,
        CampaignPerformanceReportColumn::CampaignName,
        CampaignPerformanceReportColumn::TimePeriod,
        CampaignPerformanceReportColumn::Spend,
        CampaignPerformanceReportColumn::Clicks,
        CampaignPerformanceReportColumn::Impressions,
        CampaignPerformanceReportColumn::Conversions,
    ];

    /**
     * Polling interval in seconds between status checks.
     * Microsoft recommends 2-15 minute intervals for large reports.
     */
    private const int POLL_INTERVAL_SECONDS = 10;

    /**
     * Maximum polling attempts before timeout.
     * 30 attempts × 10 seconds = 5 minutes max wait.
     */
    private const int MAX_POLL_ATTEMPTS = 30;

    /**
     * HTTP timeout for downloading report ZIP files.
     */
    private const int DOWNLOAD_TIMEOUT_SECONDS = 120;

    public function __construct(
        private readonly BingAdsSessionManager $sessionManager,
        private readonly BingAdsConfig $config,
    ) {}

    /**
     * Get account details from Customer Management service.
     *
     * Used for connectivity verification and currency validation.
     *
     * Note: SDK PHPDoc types this as AdvertiserAccount but returns stdClass at runtime.
     *
     * @return object{CurrencyCode: string, Id: int, Name: string}
     *
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getAccount(): object
    {
        try {
            $service = $this->createCustomerManagementService();

            $request = new GetAccountRequest();
            $request->AccountId = (int) $this->config->accountId;

            /** @var SoapClient $soapClient */
            $soapClient = $service->GetService();

            /** @var object{Account: object{CurrencyCode: string, Id: int, Name: string}} $response */
            $response = $soapClient->GetAccount($request);

            return $response->Account;
        } catch (SoapFault $e) {
            throw $this->handleSoapFault($e);
        } catch (Exception $e) {
            // SDK initialization errors (WSDL loading, network issues)
            throw $this->handleServerError($e);
        }
    }

    /**
     * Get campaign performance report as CSV content.
     *
     * Handles the full async flow: submit → poll → download ZIP → extract CSV.
     *
     * @return string|null CSV content, or null if report has no data for date range
     *
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable or report generation fails
     */
    public function getCampaignPerformanceReportCsv(DateRange $range): ?string
    {
        try {
            $service = $this->createReportingService();

            /** @var SoapClient $soapClient */
            $soapClient = $service->GetService();

            // Submit report request
            $reportRequestId = $this->submitReport($soapClient, $range);

            Log::info(self::SERVICE_NAME . ' report submitted', [
                'requestId' => $reportRequestId,
                'from' => $range->from->format('Y-m-d'),
                'to' => $range->to->format('Y-m-d'),
            ]);

            // Poll for completion
            $downloadUrl = self::pollUntilComplete($soapClient, $reportRequestId);

            if ($downloadUrl === null) {
                return null; // No data for date range
            }

            // Download and extract
            $zipContent = $this->downloadReport($downloadUrl);

            return self::extractCsvFromZip($zipContent);
        } /** @noinspection PhpRedundantCatchClauseInspection */ catch (SoapFault $e) {
            throw $this->handleSoapFault($e);
        } catch (Exception $e) {
            throw $this->handleServerError($e);
        }
    }

    /**
     * Submit a campaign performance report request.
     *
     * @return string Report request ID for polling
     */
    private function submitReport(SoapClient $soapClient, DateRange $range): string
    {
        $reportRequest = $this->buildCampaignPerformanceReportRequest($range);

        $submitRequest = new SubmitGenerateReportRequest();
        // @phpstan-ignore assign.propertyType (SDK PHPDoc says ReportRequest but SOAP requires SoapVar wrapper)
        $submitRequest->ReportRequest = $reportRequest;

        /** @var object{ReportRequestId: string} $response */
        $response = $soapClient->SubmitGenerateReport($submitRequest);

        return $response->ReportRequestId;
    }

    /**
     * Build a CampaignPerformanceReportRequest with daily aggregation.
     *
     * Must wrap in SoapVar for proper SOAP serialization of derived type.
     */
    private function buildCampaignPerformanceReportRequest(DateRange $range): SoapVar
    {
        // SDK properties are untyped but PHPDoc claims enum types - all assignments below are valid at runtime
        $report = new CampaignPerformanceReportRequest();
        $report->Format = ReportFormat::Csv; // @phpstan-ignore assign.propertyType
        $report->ReportName = 'Campaign Performance Report';
        $report->ReturnOnlyCompleteData = true;
        $report->Aggregation = ReportAggregation::Daily; // @phpstan-ignore assign.propertyType

        // Scope to configured account
        $report->Scope = new AccountThroughCampaignReportScope();
        $report->Scope->AccountIds = [(int) $this->config->accountId];
        $report->Scope->Campaigns = null; // @phpstan-ignore assign.propertyType

        // Custom date range
        $report->Time = new ReportTime();
        $report->Time->CustomDateRangeStart = $this->createReportDate($range->from);
        $report->Time->CustomDateRangeEnd = $this->createReportDate($range->to);

        // Columns for CampaignMetrics
        $report->Columns = self::REPORT_COLUMNS; // @phpstan-ignore assign.propertyType

        // Wrap in SoapVar for SOAP inheritance serialization
        return new SoapVar(
            $report,
            SOAP_ENC_OBJECT,
            'CampaignPerformanceReportRequest',
            'https://bingads.microsoft.com/Reporting/v13',
        );
    }

    /**
     * Create a Bing Ads Date object from DateTimeImmutable.
     */
    private function createReportDate(DateTimeImmutable $dateTime): Date
    {
        $date = new Date();
        $date->Day = (int) $dateTime->format('d');
        $date->Month = (int) $dateTime->format('m');
        $date->Year = (int) $dateTime->format('Y');

        return $date;
    }

    /**
     * Poll for report completion.
     *
     * @return string|null Download URL, or null if report has no data
     *
     * @throws ExternalServiceUnavailableException When report generation fails or times out
     */
    private static function pollUntilComplete(SoapClient $soapClient, string $reportRequestId): ?string
    {
        $pollRequest = new PollGenerateReportRequest();
        $pollRequest->ReportRequestId = $reportRequestId;

        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            // Wait before polling (except first attempt)
            if ($attempt > 1) {
                \sleep(self::POLL_INTERVAL_SECONDS);
            }

            /** @var object{ReportRequestStatus: object{Status: string, ReportDownloadUrl: ?string}} $response */
            $response = $soapClient->PollGenerateReport($pollRequest);

            $status = $response->ReportRequestStatus->Status;

            if ($status === ReportRequestStatusType::Success) {
                $downloadUrl = $response->ReportRequestStatus->ReportDownloadUrl;

                Log::info(self::SERVICE_NAME . ' report ready', [
                    'requestId' => $reportRequestId,
                    'hasData' => $downloadUrl !== null,
                    'attempts' => $attempt,
                ]);

                // URL can be null if report has no data for the date range
                return $downloadUrl;
            }

            if ($status === ReportRequestStatusType::Error) {
                Log::error(self::SERVICE_NAME . ' report generation failed', [
                    'requestId' => $reportRequestId,
                    'attempts' => $attempt,
                ]);

                throw new ExternalServiceUnavailableException(
                    self::SERVICE_NAME,
                    previous: new RuntimeException('Report generation failed'),
                );
            }

            // Status is Pending - continue polling
            Log::debug(self::SERVICE_NAME . ' report pending', [
                'requestId' => $reportRequestId,
                'attempt' => $attempt,
            ]);
        }

        // Exceeded max attempts
        Log::warning(self::SERVICE_NAME . ' report polling timeout', [
            'requestId' => $reportRequestId,
            'attempts' => self::MAX_POLL_ATTEMPTS,
        ]);

        throw new ExternalServiceUnavailableException(
            self::SERVICE_NAME,
            retryAfter: 60,
            previous: new RuntimeException('Report generation timed out'),
        );
    }

    /**
     * Download report ZIP from temporary URL.
     *
     * @throws RequestException When download fails
     */
    private function downloadReport(string $url): string
    {
        $response = Http::timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
            ->send('GET', $url)
            ->throw();

        $content = $response->body();

        Log::info(self::SERVICE_NAME . ' report downloaded', [
            'size' => \mb_strlen($content),
        ]);

        return $content;
    }

    /**
     * Extract CSV content from ZIP data.
     *
     * @throws RuntimeException When ZIP is invalid or contains no CSV
     */
    private static function extractCsvFromZip(string $zipContent): string
    {
        $tempFile = \tempnam(\sys_get_temp_dir(), 'bing_report_');

        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temporary file');
        }

        try {
            if (\file_put_contents($tempFile, $zipContent) === false) {
                throw new RuntimeException('Failed to write ZIP content');
            }

            $zip = new ZipArchive();
            $result = $zip->open($tempFile);

            if ($result !== true) {
                throw new RuntimeException("Failed to open ZIP (error: {$result})");
            }

            try {
                // Find CSV file in archive
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $filename = $zip->getNameIndex($i);

                    if ($filename !== false && \str_ends_with(\mb_strtolower($filename), '.csv')) {
                        $csv = $zip->getFromName($filename);

                        if ($csv === false) {
                            throw new RuntimeException("Failed to read CSV: {$filename}");
                        }

                        return $csv;
                    }
                }

                throw new RuntimeException('No CSV file found in ZIP');
            } finally {
                $zip->close();
            }
        } finally {
            @\unlink($tempFile);
        }
    }

    /**
     * Create a Reporting service client with fresh OAuth token.
     *
     * @throws Exception When WSDL loading or SDK initialization fails
     */
    private function createReportingService(): ServiceClient
    {
        $session = $this->sessionManager->getSession();
        $authorizationData = $this->buildAuthorizationData($session);

        $environment = ($this->config->environment === 'Production')
            ? ApiEnvironment::Production
            : ApiEnvironment::Sandbox;

        return new ServiceClient(
            ServiceClientType::ReportingVersion13,
            $authorizationData,
            $environment,
        );
    }

    /**
     * Create a Customer Management service client with fresh OAuth token.
     *
     * @throws Exception When WSDL loading or SDK initialization fails
     */
    private function createCustomerManagementService(): ServiceClient
    {
        $session = $this->sessionManager->getSession();
        $authorizationData = $this->buildAuthorizationData($session);

        $environment = ($this->config->environment === 'Production')
            ? ApiEnvironment::Production
            : ApiEnvironment::Sandbox;

        return new ServiceClient(
            ServiceClientType::CustomerManagementVersion13,
            $authorizationData,
            $environment,
        );
    }

    /**
     * Build SDK AuthorizationData from our session.
     */
    private function buildAuthorizationData(BingAdsSession $session): AuthorizationData
    {
        $oauthTokens = new OAuthTokens()
            ->withAccessToken($session->accessToken);

        // SDK PHPDoc says @param string, but property is typed OAuthTokens and
        // ServiceClient accesses ->AccessToken on it. PHPDoc is wrong.
        /** @noinspection PhpParamsInspection */
        $authentication = new OAuthDesktopMobileAuthCodeGrant()
            ->withOAuthTokens($oauthTokens); // @phpstan-ignore argument.type

        return new AuthorizationData()
            ->withAuthentication($authentication)
            ->withDeveloperToken($this->config->developerToken)
            ->withAccountId($this->config->accountId)
            ->withCustomerId($this->config->customerId);
    }

    /**
     * Route SOAP faults to specific handlers by error code.
     */
    private function handleSoapFault(SoapFault $e): AuthenticationExpiredException|ExternalServiceUnavailableException
    {
        $errorCode = self::extractErrorCode($e);

        if (\in_array($errorCode, self::AUTH_ERROR_CODES, true)) {
            return $this->handleAuthenticationFailure($e, $errorCode);
        }

        if (\in_array($errorCode, self::RATE_LIMIT_CODES, true)) {
            return $this->handleRateLimit($e);
        }

        return $this->handleServerError($e);
    }

    /**
     * Extract numeric error code from SOAP fault.
     *
     * SOAP fault details have dynamic structure depending on error type.
     * We check for two known structures: ApiFaultDetail and AdApiFaultDetail.
     */
    private static function extractErrorCode(SoapFault $e): ?int
    {
        // SOAP fault detail is untyped - structure varies by error type
        if (!isset($e->detail)) {
            return null;
        }

        /**
         * SOAP fault detail is untyped stdClass with dynamic structure.
         *
         * @var object{
         *     ApiFaultDetail?: object{OperationErrors?: object{OperationError?: object{Code?: int}|array<int, object{Code?: int}>}},
         *     AdApiFaultDetail?: object{Errors?: object{AdApiError?: object{Code?: int}|array<int, object{Code?: int}>}}
         * } $detail
         */
        $detail = $e->detail;

        // ApiFaultDetail structure (operation-level errors)
        // @phpstan-ignore-next-line (SOAP detail has dynamic structure with nested properties)
        if (isset($detail->ApiFaultDetail->OperationErrors->OperationError)) {
            $operationError = $detail->ApiFaultDetail->OperationErrors->OperationError;
            $error = \is_array($operationError) ? $operationError[0] : $operationError;

            if (isset($error->Code)) {
                return (int) $error->Code; // @phpstan-ignore cast.useless (PHPDoc says int but runtime may differ)
            }
        }

        // AdApiFaultDetail structure (API-level errors)
        // @phpstan-ignore-next-line (SOAP detail has dynamic structure with nested properties)
        if (isset($detail->AdApiFaultDetail->Errors->AdApiError)) {
            $adApiError = $detail->AdApiFaultDetail->Errors->AdApiError;
            $error = \is_array($adApiError) ? $adApiError[0] : $adApiError;

            if (isset($error->Code)) {
                return (int) $error->Code; // @phpstan-ignore cast.useless (PHPDoc says int but runtime may differ)
            }
        }

        return null;
    }

    /**
     * Handle authentication failure - permanent, needs credential fix.
     */
    private function handleAuthenticationFailure(SoapFault $e, ?int $code): AuthenticationExpiredException
    {
        Log::error(self::SERVICE_NAME . ' authentication failed', [
            'code' => $code,
            'error' => $e->getMessage(),
        ]);

        // Invalidate cached session so next request gets fresh token
        $this->sessionManager->invalidate();

        return new AuthenticationExpiredException(
            self::SERVICE_NAME,
            $e->getMessage(),
            $e,
        );
    }

    /**
     * Handle rate limit - transient, include retry delay.
     */
    private function handleRateLimit(SoapFault $e): ExternalServiceUnavailableException
    {
        Log::warning(self::SERVICE_NAME . ' rate limited', [
            'error' => $e->getMessage(),
        ]);

        // Microsoft recommends 60 second backoff for rate limits
        return new ExternalServiceUnavailableException(self::SERVICE_NAME, 60, $e);
    }

    /**
     * Handle server/SDK errors - transient.
     *
     * Handles both SOAP faults and SDK initialization errors (WSDL loading, network issues).
     */
    private function handleServerError(Exception $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' error', [
            'type' => $e::class,
            'code' => $e->getCode(),
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
