<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
                throw new RuntimeException('SUPABASE_JWT_SECRET not configured');
            }

            // Validate and decode JWT using HS256 algorithm (Supabase default)
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Extract user information from JWT claims
            $userId = $decoded->sub ?? null;
            $userEmail = $decoded->email ?? null;

            if (($userId === null) || ($userId === '')) {
                throw new RuntimeException('JWT token missing required "sub" claim');
            }

            // Attach user information to request for use in controllers
            $request->merge([
                'auth_user_id' => $userId,
                'auth_user_email' => $userEmail,
            ]);

            return $next($request);

        } catch (Throwable $e) {
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
     * Attaches fake user credentials from config for local testing.
     *
     * @param Closure(Request): Response $next
     */
    private function handleLocalBypass(Request $request, Closure $next): Response
    {
        /** @var string $testEmail */
        $testEmail = \config('services.supabase.local_test_email');

        Log::channel('security')->debug('Local auth bypass activated', [
            'event' => 'api.auth.local_bypass',
            'ip' => $request->ip(),
            'path' => $request->path(),
            'test_email' => $testEmail,
        ]);

        // Attach fake user credentials for local testing
        $request->merge([
            'auth_user_id' => 'local-test-user',
            'auth_user_email' => $testEmail,
        ]);

        return $next($request);
    }
}
