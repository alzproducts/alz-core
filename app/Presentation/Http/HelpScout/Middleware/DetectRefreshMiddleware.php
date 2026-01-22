<?php

declare(strict_types=1);

namespace App\Presentation\Http\HelpScout\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Detects refresh intent from HTTP verb and sets request attribute.
 *
 * - POST request → `forceRefresh: true` (invalidate cache + fetch fresh)
 * - GET request → `forceRefresh: false` (return cached data)
 *
 * Controllers read `$request->attributes->get('forceRefresh')` to determine
 * caching behavior without caring about the HTTP verb.
 *
 * Applied to HelpScout conversation endpoints via Route::match(['get', 'post'], ...).
 */
final readonly class DetectRefreshMiddleware
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $forceRefresh = $request->isMethod('POST');

        $request->attributes->set('forceRefresh', $forceRefresh);

        return $next($request);
    }
}
