<?php

declare(strict_types=1);

namespace App\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class HorizonBasicAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip auth in local environment only if credentials aren't configured
        // This allows local dev without credentials while enforcing auth in tests/production
        if (! (\app()->environment('local') && self::credentialsNotConfigured())) {
            $this->validateCredentials($request);
        }

        $response = $next($request);

        if (! ($response instanceof Response)) {
            throw new LogicException('Middleware pipeline must return Response instance');
        }

        return $response;
    }

    private static function credentialsNotConfigured(): bool
    {
        $username = \config('horizon.auth.username');
        $password = \config('horizon.auth.password');

        return (! \is_string($username) || ($username === ''))
            && (! \is_string($password) || ($password === ''));
    }

    private function validateCredentials(Request $request): void
    {
        $username = self::getConfigValue('username');
        $password = self::getConfigValue('password');

        if (! $this->credentialsMatch($username, $password, $request)) {
            \abort(401, 'Unauthorized', [
                'WWW-Authenticate' => 'Basic realm="Horizon Dashboard"',
            ]);
        }
    }

    private static function getConfigValue(string $key): string
    {
        $value = \config("horizon.auth.{$key}");

        if (! \is_string($value) || ($value === '')) {
            \abort(500, "Horizon {$key} not configured or invalid type");
        }

        return $value;
    }

    private function credentialsMatch(string $username, string $password, Request $request): bool
    {
        return \hash_equals($username, $request->getUser() ?? '')
            && \hash_equals($password, $request->getPassword() ?? '');
    }
}
