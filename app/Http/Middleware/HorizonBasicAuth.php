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

        if (
            ($request->getUser() !== $username)
            || ($request->getPassword() !== $password)
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
