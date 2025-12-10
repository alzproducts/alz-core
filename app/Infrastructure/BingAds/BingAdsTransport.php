<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use Exception;
use Illuminate\Support\Facades\Log;
use Microsoft\BingAds\Auth\ApiEnvironment;
use Microsoft\BingAds\Auth\AuthorizationData;
use Microsoft\BingAds\Auth\OAuthDesktopMobileAuthCodeGrant;
use Microsoft\BingAds\Auth\OAuthTokens;
use Microsoft\BingAds\Auth\ServiceClient;
use Microsoft\BingAds\Auth\ServiceClientType;
use Microsoft\BingAds\V13\CustomerManagement\GetAccountRequest;
use SoapClient;
use SoapFault;

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
