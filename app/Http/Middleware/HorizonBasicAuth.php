<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

class HorizonBasicAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $username = \config('horizon.auth.username');
        $password = \config('horizon.auth.password');

        // Validate configuration: must be non-empty strings
        if (!\is_string($username) || ($username === '')) {
            \abort(500, 'Horizon username not configured or invalid type');
        }

        if (!\is_string($password) || ($password === '')) {
            \abort(500, 'Horizon password not configured or invalid type');
        }

        if (
            !\hash_equals($username, $request->getUser() ?? '')
            || !\hash_equals($password, $request->getPassword() ?? '')
        ) {
            return \response('Unauthorized', 401, [
                'WWW-Authenticate' => 'Basic realm="Horizon Dashboard"',
            ]);
        }

        $response = $next($request);

        if (! ($response instanceof Response)) {
            throw new LogicException('Middleware pipeline must return Response instance');
        }

        return $response;
    }
}
