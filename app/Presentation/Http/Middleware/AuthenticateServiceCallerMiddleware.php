<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Allows only the named service caller: its bearer token is the gate, its IP
 * allowlist is defence in depth (docs/adr/0023).
 *
 * MUST be declared AFTER the route's throttle, so a refused request is still rate limited.
 *
 * Usage: AuthenticateServiceCallerMiddleware::using('alz_connect')
 */
final class AuthenticateServiceCallerMiddleware
{
    /**
     * @param string $caller Key in config/service-callers.php
     */
    public static function using(string $caller): string
    {
        return self::class . ':' . $caller;
    }

    /**
     * @param Closure(Request): Response $next
     *
     * @throws HttpException When the token or client IP is refused
     * @throws HttpResponseException
     */
    public function handle(Request $request, Closure $next, string $caller): Response
    {
        $tokenHash = self::configValue($caller, 'token_sha256');

        if (!\hash_equals($tokenHash, \hash('sha256', $request->bearerToken() ?? ''))) {
            self::logRefusal($request, $caller, 'invalid_token');
            \abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized.');
        }

        $allowedIps = \array_map(\trim(...), \explode(',', self::configValue($caller, 'allowed_ips')));

        if (!IpUtils::checkIp($request->ip() ?? '', $allowedIps)) {
            self::logRefusal($request, $caller, 'ip_not_allowed');
            \abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
        }

        return $next($request);
    }

    private static function configValue(string $caller, string $key): string
    {
        $value = \config("service-callers.{$caller}.{$key}");

        return \is_string($value) ? \mb_trim($value) : '';
    }

    private static function logRefusal(Request $request, string $caller, string $reason): void
    {
        Log::channel('security')->warning('Service caller refused', [
            'event' => "api.service_caller.{$reason}",
            'caller' => $caller,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);
    }
}
