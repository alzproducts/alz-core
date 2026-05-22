<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use Exception;
use Illuminate\Support\Facades\Log;
use Microsoft\MsAds\Rest\Api\CampaignManagementServiceApi;
use Microsoft\MsAds\Rest\ApiException as RestApiException;
use Microsoft\MsAds\Rest\Auth\ApiEnvironment;
use Microsoft\MsAds\Rest\Auth\AuthorizationData;
use Microsoft\MsAds\Rest\Auth\OAuthDesktopMobileAuthCodeGrant;
use Microsoft\MsAds\Rest\Auth\OAuthTokens;
use Microsoft\MsAds\Rest\Configuration;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\ApplyOfflineConversionsRequest;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\ApplyOfflineConversionsResponse;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\BatchError;

/**
 * Transport layer for Bing Ads REST SDK (offline conversions).
 *
 * Wraps the REST SDK's CampaignManagementServiceApi and handles all exception
 * translation. Auth is bridged from {@see BingAdsSessionManager} per ADR-0003.
 */
final readonly class BingAdsConversionTransport
{
    private const string SERVICE_NAME = 'Bing Ads REST';

    public function __construct(
        private BingAdsSessionManager $sessionManager,
        private BingAdsConfig $config,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     */
    public function applyOfflineConversion(ApplyOfflineConversionsRequest $request): void
    {
        $api = $this->createCampaignManagementApi();

        try {
            /** @var ApplyOfflineConversionsResponse $response */
            $response = $api->applyOfflineConversions($request);
        } catch (RestApiException $e) {
            throw $this->handleApiException($e);
        } catch (Exception $e) {
            throw $this->handleServerError($e);
        }

        $this->handlePartialErrors($response);
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     */
    private function createCampaignManagementApi(): CampaignManagementServiceApi
    {
        $session = $this->sessionManager->getSession();

        $oauthTokens = (new OAuthTokens())->withAccessToken($session->accessToken);
        $authentication = (new OAuthDesktopMobileAuthCodeGrant())->withOAuthTokens($oauthTokens);

        $authorizationData = (new AuthorizationData())
            ->withAuthentication($authentication)
            ->withDeveloperToken($this->config->developerToken)
            ->withAccountId($this->config->accountId)
            ->withCustomerId($this->config->customerId);

        $configuration = new Configuration();
        $configuration->setAuthorizationData($authorizationData);

        $environment = ($this->config->environment === 'Production')
            ? ApiEnvironment::PRODUCTION
            : ApiEnvironment::SANDBOX;

        return new CampaignManagementServiceApi(null, $configuration, null, $environment);
    }

    /**
     * Translate batch-level partial failures into a single Domain exception.
     *
     * Only the first error message is preserved — safe because
     * {@see BingAdsConversionClient::uploadOfflineConversion} wraps a single
     * OfflineConversion per request. If batching is ever added, this needs to
     * aggregate all PartialErrors so failures aren't silently dropped.
     *
     * @throws InvalidApiRequestException When at least one conversion was rejected
     */
    private function handlePartialErrors(ApplyOfflineConversionsResponse $response): void
    {
        /** @var list<BatchError>|null $partialErrors */
        $partialErrors = $response->getPartialErrors();

        if ($partialErrors === null || $partialErrors === []) {
            return;
        }

        $firstError = $partialErrors[0];
        $code = $firstError->getCode();
        $message = $firstError->getMessage() ?? 'Unknown partial error';

        Log::error(self::SERVICE_NAME . ' conversion upload partial errors', [
            'count' => \count($partialErrors),
            'first_code' => $code,
            'first_message' => $message,
        ]);

        // Bing's BatchError message can echo back rejected user input (email, msclkid, phone)
        // depending on the error type. Keep the full message in logs only; the exception carries
        // the code for Sentry grouping without risking PII in error tracking.
        throw new InvalidApiRequestException(
            self::SERVICE_NAME,
            \sprintf('Conversion rejected by Bing (code: %s)', $code ?? 'unknown'),
        );
    }

    private function handleApiException(RestApiException $e): AuthenticationExpiredException|ExternalServiceUnavailableException|InvalidApiRequestException
    {
        $statusCode = $e->getCode();
        $trackingId = $this->extractTrackingId($e);

        if ($statusCode === 400) {
            return $this->handleBadRequest($e, $trackingId);
        }

        if (\in_array($statusCode, [401, 403], true)) {
            return $this->handleAuthenticationFailure($e, $trackingId);
        }

        if ($statusCode === 429) {
            return $this->handleRateLimit($e, $trackingId);
        }

        return $this->handleHttpError($e, $trackingId);
    }

    private function handleBadRequest(RestApiException $e, ?string $trackingId): InvalidApiRequestException
    {
        Log::error(self::SERVICE_NAME . ' API rejected request', [
            'status' => $e->getCode(),
            'error' => $e->getMessage(),
            'tracking_id' => $trackingId,
        ]);

        return new InvalidApiRequestException(self::SERVICE_NAME, $e->getMessage(), $e);
    }

    private function handleAuthenticationFailure(RestApiException $e, ?string $trackingId): AuthenticationExpiredException
    {
        Log::error(self::SERVICE_NAME . ' authentication failed', [
            'status' => $e->getCode(),
            'error' => $e->getMessage(),
            'tracking_id' => $trackingId,
        ]);

        $this->sessionManager->invalidate();

        return new AuthenticationExpiredException(self::SERVICE_NAME, $e->getMessage(), $e);
    }

    private function handleRateLimit(RestApiException $e, ?string $trackingId): ExternalServiceUnavailableException
    {
        Log::warning(self::SERVICE_NAME . ' rate limited', [
            'error' => $e->getMessage(),
            'tracking_id' => $trackingId,
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, 60, $e);
    }

    private function handleHttpError(RestApiException $e, ?string $trackingId): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' API error', [
            'status' => $e->getCode(),
            'error' => $e->getMessage(),
            'tracking_id' => $trackingId,
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    private function handleServerError(Exception $e): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' unexpected error', [
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }

    private function extractTrackingId(RestApiException $e): ?string
    {
        $headers = $e->getResponseHeaders();

        if ($headers === null) {
            return null;
        }

        return $headers['TrackingId'][0] ?? $headers['trackingid'][0] ?? null;
    }
}
