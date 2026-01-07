<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Presentation\Http\Auth\SupabaseJwtParser;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ValidateSupabaseJwtMiddleware
{
    /**
     * Header name for local testing bypass.
     */
    private const string LOCAL_BYPASS_HEADER = 'X-Local-Bypass';

    /**
     * Validate Supabase JWT token and attach user information to the request.
     *
     * Security checks performed:
     * 1. Token presence and validity (signature, expiration)
     * 2. MFA enforcement (AAL2 required - prevents bypassing frontend MFA)
     * 3. Custom claims extraction (is_approved, role_name, departments for authorization)
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for local testing bypass (development only)
        if ($this->shouldBypassAuth($request)) {
            return $this->handleLocalBypass($request, $next);
        }

        $token = $request->bearerToken();

        if (($token === null) || ($token === '')) {
            Log::channel('security')->warning('Missing authorization token', [
                'event' => 'api.auth.missing_token',
                'ip' => $request->ip(),
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
            ]);

            return \response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $secret = \config('services.supabase.jwt_secret');

            if (!\is_string($secret) || ($secret === '')) {
                throw new InvalidConfigurationException('services.supabase.jwt_secret', 'SUPABASE_JWT_SECRET not configured');
            }

            // Validate and decode JWT using HS256 algorithm (Supabase default)
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Parse and validate JWT claims (throws InvalidJwtClaimsException if malformed)
            $claims = SupabaseJwtParser::fromDecodedJwt($decoded);

            // =================================================================
            // CRITICAL SECURITY: MFA Enforcement
            // =================================================================
            // Frontend enforces MFA via Supabase Auth AAL level check.
            // We MUST also enforce AAL2 to prevent API-only access bypass.
            // Without this, an attacker with a valid AAL1 token could bypass
            // the frontend and access the API without completing MFA.
            // =================================================================
            if (!$claims->isMfaVerified()) {
                Log::channel('security')->warning('MFA not verified - AAL2 required', [
                    'event' => 'api.auth.mfa_required',
                    'user_id' => $claims->userId,
                    'email' => $claims->email,
                    'aal_level' => $claims->aal,
                    'ip' => $request->ip(),
                    'path' => $request->path(),
                ]);

                return \response()->json([
                    'error' => 'MFA verification required',
                    'code' => 'MFA_REQUIRED',
                ], 403);
            }

            // Convert to domain value object and attach to request
            $authenticatedUser = $claims->toAuthenticatedUser();
            $request->attributes->set('authenticated_user', $authenticatedUser);

            return $next($request);

        } catch (Throwable $e) { // @ignoreException - auth middleware: return 401 on any validation failure
            Log::channel('security')->warning('Invalid JWT token', [
                'event' => 'api.auth.invalid_token',
                'ip' => $request->ip(),
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'error' => $e->getMessage(),
            ]);

            return \response()->json(['error' => 'Unauthorized'], 401);
        }
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
        // Must be local environment
        if (\app()->environment() !== 'local') {
            return false;
        }

        // Must be from localhost
        $ip = $request->ip();
        if (($ip !== '127.0.0.1') && ($ip !== '::1')) {
            return false;
        }

        // Must have bypass secret configured
        $bypassSecret = \config('services.supabase.local_bypass_secret');
        if (!\is_string($bypassSecret) || ($bypassSecret === '')) {
            return false;
        }

        // Header must match the configured secret exactly
        $bypassHeader = $request->header(self::LOCAL_BYPASS_HEADER);
        if ($bypassHeader !== $bypassSecret) {
            return false;
        }

        // Must have local test email configured
        $testEmail = \config('services.supabase.local_test_email');

        return \is_string($testEmail) && ($testEmail !== '');
    }

    /**
     * Handle local bypass authentication.
     *
     * Creates an AuthenticatedUser from config for local testing.
     * Uses the same request attribute as real JWT auth for consistent downstream handling.
     *
     * @param Closure(Request): Response $next
     */
    private function handleLocalBypass(Request $request, Closure $next): Response
    {
        // Type guaranteed by shouldBypassAuth() which validates non-empty string
        $testEmail = \config('services.supabase.local_test_email');
        \assert(\is_string($testEmail) && $testEmail !== '');

        $testUserId = \config('services.supabase.local_test_user_id', '00000000-0000-0000-0000-000000000001');
        $testApproved = \config('services.supabase.local_test_approved', true);
        $testRole = \config('services.supabase.local_test_role', 'admin');
        $testDepartments = \config('services.supabase.local_test_departments');

        Log::channel('security')->debug('Local auth bypass activated', [
            'event' => 'api.auth.local_bypass',
            'ip' => $request->ip(),
            'path' => $request->path(),
            'test_email' => $testEmail,
            'test_user_id' => $testUserId,
            'test_role' => $testRole,
        ]);

        // Create AuthenticatedUser from config values
        $authenticatedUser = new AuthenticatedUser(
            id: \is_string($testUserId) ? $testUserId : '00000000-0000-0000-0000-000000000001',
            email: $testEmail,
            isApproved: (bool) $testApproved,
            roleName: \is_string($testRole) ? $testRole : null,
            departments: self::parseDepartmentsConfig($testDepartments),
        );

        $request->attributes->set('authenticated_user', $authenticatedUser);

        return $next($request);
    }

    /**
     * Parse departments config value to array format.
     *
     * @return list<string>|null
     */
    private static function parseDepartmentsConfig(mixed $value): ?array
    {
        if (\is_array($value)) {
            if ($value === []) {
                return null;
            }
            /** @var list<string> */
            return \array_values($value);
        }

        if (\is_string($value) && $value !== '') {
            return \explode(',', $value);
        }

        return null;
    }
}
