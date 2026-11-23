<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
     */
    public function handle(Request $request, Closure $next, string $caller): Response
    {
        $tokenHash = self::configValue($caller, 'token_sha256');
        $allowedIps = self::allowedIps($caller);

        if ($tokenHash === '' || $allowedIps === []) {
            return $this->rejectUnconfigured($request, $caller);
        }

        if (!self::hasValidToken($request, $tokenHash)) {
            return $this->rejectToken($request, $caller);
        }

        if (!self::isAllowedIp($request->ip(), $allowedIps)) {
            return $this->rejectIp($request, $caller);
        }

        return $next($request);
    }

    private static function configValue(string $caller, string $key): string
    {
        $value = \config("service-callers.{$caller}.{$key}");

        return \is_string($value) ? \mb_trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private static function allowedIps(string $caller): array
    {
        $entries = \array_map(\trim(...), \explode(',', self::configValue($caller, 'allowed_ips')));

        return \array_values(\array_filter($entries, static fn(string $entry): bool => $entry !== ''));
    }

    private static function hasValidToken(Request $request, string $tokenHash): bool
    {
        // intentional: not $request->bearerToken(), which accepts text before "Bearer".
        $matched = \preg_match('/\ABearer +(\S+)\z/i', $request->headers->get('Authorization') ?? '', $matches);

        return $matched === 1 && \hash_equals($tokenHash, \hash('sha256', $matches[1]));
    }

    /**
     * @param list<string> $allowedIps
     */
    private static function isAllowedIp(?string $clientIp, array $allowedIps): bool
    {
        $client = self::packedAddress($clientIp ?? '');

        return $client !== null
            && \array_any($allowedIps, static fn(string $entry): bool => self::packedAddress($entry) === $client);
    }

    private static function packedAddress(string $address): ?string
    {
        // intentional: inet_pton() warns on invalid input, and Laravel throws on a warning.
        if (\filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $packed = \inet_pton($address);

        return $packed === false ? null : $packed;
    }

    private function rejectUnconfigured(Request $request, string $caller): JsonResponse
    {
        Log::channel('security')->critical('Service caller not configured', [
            'event' => 'api.service_caller.not_configured',
            ...self::logContext($request, $caller),
        ]);

        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::ServiceUnavailable,
            message: 'Service unavailable.',
            status: Response::HTTP_SERVICE_UNAVAILABLE,
        )->toJsonResponse();
    }

    private function rejectToken(Request $request, string $caller): JsonResponse
    {
        Log::channel('security')->warning('Service caller token rejected', [
            'event' => 'api.service_caller.invalid_token',
            ...self::logContext($request, $caller),
        ]);

        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Unauthorized,
            message: 'Unauthorized.',
            status: Response::HTTP_UNAUTHORIZED,
        )->toJsonResponse();
    }

    private function rejectIp(Request $request, string $caller): JsonResponse
    {
        Log::channel('security')->warning('Service caller IP not allowed', [
            'event' => 'api.service_caller.ip_not_allowed',
            ...self::logContext($request, $caller),
        ]);

        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Forbidden,
            message: 'Forbidden.',
            status: Response::HTTP_FORBIDDEN,
        )->toJsonResponse();
    }

    /**
     * @return array<string, string|null>
     */
    private static function logContext(Request $request, string $caller): array
    {
        return [
            'caller' => $caller,
            'path' => $request->path(),
            'ip' => $request->ip(),
        ];
    }
}
