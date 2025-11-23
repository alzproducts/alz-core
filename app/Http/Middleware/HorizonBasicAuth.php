<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

final class HorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Skip auth in local development, but always validate in testing
        if (! (\app()->environment('local') && ! \app()->environment('testing'))) {
            $this->validateCredentials($request);
        }

        $response = $next($request);

        if (! ($response instanceof Response)) {
            throw new LogicException('Middleware pipeline must return Response instance');
        }

        return $response;
    }

    private function validateCredentials(Request $request): void
    {
        $username = $this->getConfigValue('username');
        $password = $this->getConfigValue('password');

        if (! $this->credentialsMatch($username, $password, $request)) {
            \abort(401, 'Unauthorized', [
                'WWW-Authenticate' => 'Basic realm="Horizon Dashboard"',
            ]);
        }
    }

    private function getConfigValue(string $key): string
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
