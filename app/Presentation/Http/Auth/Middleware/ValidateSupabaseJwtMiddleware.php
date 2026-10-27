<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth\Middleware;

use App\Application\Auth\TestUserPersonaResolver;
use App\Application\Contracts\Access\AccessTokenVerifierInterface;
use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Access\ValueObjects\AccessTokenClaims;
use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final readonly class ValidateSupabaseJwtMiddleware
{
    /**
     * Header name for local testing bypass.
     */
    private const string LOCAL_BYPASS_HEADER = 'X-Local-Bypass';

    public function __construct(
        private AccessTokenVerifierInterface $verifier,
    ) {}

    /**
     * Validate Supabase JWT token and attach user information to the request.
     *
     * Security checks performed:
     * 1. Token presence and validity (signature, expiration, issuer, audience)
     * 2. MFA enforcement (AAL2 required - prevents bypassing frontend MFA)
     * 3. Custom claims extraction (is_approved, role_name, departments for authorization)
     *
     * @param Closure(Request): Response $next
     *
     * @throws RuntimeException If local bypass is enabled but persona not configured
     * @throws ExternalServiceUnavailableException When the signing keys cannot be fetched (rendered as 503)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldBypassAuth($request)) {
            return $this->handleLocalBypass($request, $next);
        }

        $token = $request->bearerToken();
        if (($token === null) || ($token === '')) {
            return $this->rejectMissingToken($request);
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (InvalidAccessTokenException $e) {
            $this->logInvalidToken($request, $e);

            return self::unauthorizedResponse('Invalid or expired token.');
        }

        return $this->enforceMfaAndAuthenticate($request, $next, $claims);
    }

    /**
     * Enforce MFA (AAL2) and attach authenticated user to request.
     *
     * CRITICAL SECURITY: Frontend enforces MFA via Supabase Auth AAL level check.
     * We MUST also enforce AAL2 to prevent API-only access bypass.
     * Without this, an attacker with a valid AAL1 token could bypass
     * the frontend and access the API without completing MFA.
     *
     * @param Closure(Request): Response $next
     */
    private function enforceMfaAndAuthenticate(Request $request, Closure $next, AccessTokenClaims $claims): Response
    {
        if (!$claims->isMfaVerified()) {
            return $this->rejectMfaRequired($request, $claims);
        }

        $request->attributes->set('authenticated_user', $claims->toAuthenticatedUser());

        return $next($request);
    }

    private function rejectMissingToken(Request $request): Response
    {
        Log::channel('security')->warning('Missing authorization token', [
            'event' => 'api.auth.missing_token',
            'ip' => $request->ip(),
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
        ]);

        return self::unauthorizedResponse('Missing authorization token.');
    }

    private function rejectMfaRequired(Request $request, AccessTokenClaims $claims): Response
    {
        Log::channel('security')->warning('MFA not verified - AAL2 required', [
            'event' => 'api.auth.mfa_required',
            'user_id' => $claims->userId,
            'email' => $claims->email,
            'aal_level' => $claims->aal,
            'ip' => $request->ip(),
            'path' => $request->path(),
        ]);

        return (new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'MFA verification required.',
            status: Response::HTTP_FORBIDDEN,
        ))->toJsonResponse();
    }

    private function logInvalidToken(Request $request, InvalidAccessTokenException $e): void
    {
        Log::channel('security')->warning('Invalid JWT token', [
            'event' => 'api.auth.invalid_token',
            'ip' => $request->ip(),
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
            'error' => $e->getMessage(),
            ...$e->context(),
        ]);
    }

    private static function unauthorizedResponse(string $message): Response
    {
        return (new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Unauthorized,
            message: $message,
            status: Response::HTTP_UNAUTHORIZED,
        ))->toJsonResponse();
    }

    /**
     * Check if request should bypass JWT authentication.
     *
     * Bypass is allowed only when ALL conditions are met:
     * - Environment is 'local'
     * - Request comes from localhost (127.0.0.1 or ::1)
     * - X-Local-Bypass header matches configured secret
     * - Local test email is configured
     */
    private function shouldBypassAuth(Request $request): bool
    {
        if (\app()->environment() !== 'local') {
            return false;
        }

        return $this->isLocalhost($request) && $this->hasValidBypassCredentials($request);
    }

    private function isLocalhost(Request $request): bool
    {
        $ip = $request->ip();

        return ($ip === '127.0.0.1') || ($ip === '::1');
    }

    private function hasValidBypassCredentials(Request $request): bool
    {
        $bypassSecret = \config('services.supabase.local_bypass_secret');
        if (!\is_string($bypassSecret) || ($bypassSecret === '')) {
            return false;
        }

        $bypassHeader = $request->header(self::LOCAL_BYPASS_HEADER);
        if (!\is_string($bypassHeader) || !\hash_equals($bypassSecret, $bypassHeader)) {
            return false;
        }

        $testEmail = \config('services.supabase.local_test_email');

        return \is_string($testEmail) && ($testEmail !== '');
    }

    /**
     * Handle local bypass authentication.
     *
     * Resolves test email to a full persona (with real developer email for
     * external services like HelpScout). Requires persona configuration in
     * config/local-development.php.
     *
     * @param Closure(Request): Response $next
     *
     * @throws RuntimeException If test email not in allow-list or env var not configured
     */
    private function handleLocalBypass(Request $request, Closure $next): Response
    {
        $testEmail = \config('services.supabase.local_test_email');
        \assert(\is_string($testEmail) && $testEmail !== '');

        $authenticatedUser = TestUserPersonaResolver::fromConfig()->resolve($testEmail);
        $this->logLocalBypass($request, $testEmail, $authenticatedUser);
        $request->attributes->set('authenticated_user', $authenticatedUser);

        return $next($request);
    }

    private function logLocalBypass(Request $request, string $testEmail, AuthenticatedUser $authenticatedUser): void
    {
        Log::channel('security')->debug('Local auth bypass activated', [
            'event' => 'api.auth.local_bypass',
            'ip' => $request->ip(),
            'path' => $request->path(),
            'test_email' => $testEmail,
            'resolved_email' => $authenticatedUser->email,
            'user_id' => $authenticatedUser->id,
            'role' => $authenticatedUser->roleName,
        ]);
    }
}
