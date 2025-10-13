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

        if (
            (($username === null) || ($username === ''))
            || (($password === null) || ($password === ''))
        ) {
            \abort(500, 'Horizon authentication not configured');
        }

        // Type narrowing: after validation, these are guaranteed to be non-empty strings
        \assert((\is_string($username)));
        \assert(\is_string($password));

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
