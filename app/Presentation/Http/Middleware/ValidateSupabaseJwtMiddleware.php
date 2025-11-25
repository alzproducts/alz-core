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
     * Validate Supabase JWT token and attach user information to the request.
     *
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
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

            if (!\is_string($secret) || $secret === '') {
                throw new RuntimeException('SUPABASE_JWT_SECRET not configured');
            }

            // Validate and decode JWT using HS256 algorithm (Supabase default)
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            // Extract user information from JWT claims
            $userId = $decoded->sub ?? null;
            $userEmail = $decoded->email ?? null;

            if ($userId === null || $userId === '') {
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
}
