<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces `Accept: application/json` on all `/api/*` requests.
 *
 * Registered globally (not in the `api` route group) so it fires even for
 * routing-time 404s — where route-level middleware never runs.
 * The internal `is('api/*')` check prevents leaking JSON normalisation
 * to non-API routes (web, Horizon, /up).
 *
 * MUST run BEFORE route resolution so `expectsJson()` is already true
 * when Laravel throws NotFoundHttpException for unmatched URLs.
 */
final class ForceJsonResponseMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
